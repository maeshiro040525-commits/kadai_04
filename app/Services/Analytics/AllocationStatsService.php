<?php

namespace App\Services\Analytics;

use App\Models\EmploeeAssignment;
use App\Models\EmploeeRequirement;
use App\Models\Facility;
use App\Models\WorkStyleSlotRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * 配置（必要人数 / 実人数 / 不足）の集計を担当する Service。
 *
 * パターンB：取得（DBクエリ）＋計算の両方を持つ。AllocationController と Home（人員不足KPI）から再利用される。
 *
 * 設計メモ（2026-05-29 棚卸し決定 / 2026-06-01 段階4反映）:
 *  - demand（必要）= EmploeeRequirement（施設×月×時間帯・手編集）
 *  - supply（実人数）平日 = 自動算出（固定勤務=時間帯展開／ローテーション=WorkStyleSlotRequirement で分配＋調整枠）
 *  - supply（実人数）土曜 = 「勤務可の人数」KPI（getSaturdayCapableHeadcount）。30分マトリクスは廃止。
 *  - 不足 = 平日 demand − supply（土曜は「勤務可N人」で戦力プールが足りるかを見る）
 *  - 土曜KPI = 土曜勤務可の職員「人数」（is_saturday_target 勤務タイプに配属された職員のカウント）
 */
class AllocationStatsService
{
    /**
     * 指定施設・年月の配置マトリクス（平日の必要・実人数・差分、アラート）を返す。
     * 土曜は本メソッドでは扱わない（→ getSaturdayCapableHeadcount で「勤務可人数」を別取得）。
     *
     * @return array{
     *     weekdayRows: array<int, array<string, mixed>>,
     *     allocationAlerts: array<int, string>
     * }
     */
    public function getMatrix(Facility $facility, string $yearMonth): array
    {
        $facilityId = (int) $facility->id;

        // 固定枠を作る（open〜closeを30分単位）。平日のみ（土曜は勤務可人数で扱うためマトリクス不要）。
        $weekdaySlots = $this->buildSlots($facility->open_time, $facility->close_time);

        // 保存済みの必要人数を取得（キーは day_type|start|end）。
        // 土曜の EmploeeRequirement は本画面では表示しないが、DB上は保持（編集UIは将来の検討事項）。
        $reqs = EmploeeRequirement::where('facility_id', $facilityId)
            ->where('year_month', $yearMonth)
            ->where('day_type', 'weekday')
            ->get()
            ->keyBy(
                fn ($r) => $this->slotKey(
                    (string) $r->day_type,
                    substr((string) $r->start_time, 0, 5),
                    substr((string) $r->end_time, 0, 5)
                )
            );

        // 平日実数を職員配属から自動計算し、不足アラートも生成
        [$weekdayActuals, $allocationAlerts] = $this->buildWeekdayAutoActualsAndAlerts($facilityId, $yearMonth);

        // 表示用の行を作る（差分列もここで計算）
        $weekdayRows = $this->buildMatrixRows('weekday', $weekdaySlots, $reqs, $weekdayActuals);

        return [
            'weekdayRows' => $weekdayRows,
            'allocationAlerts' => $allocationAlerts,
        ];
    }

    /**
     * 「土曜勤務可の職員人数」を資格別に集計（土曜KPI用・2026-05-29 新規）。
     * 対象: 指定施設・指定月に有効な配属 × 勤務タイプが is_saturday_target=true。
     * 粒度: 頭数（1人=1。FTE換算は将来拡張可）。
     *
     * 「特定の土曜に誰が出るか（出勤割当）」は見ない＝シフト管理には踏み込まない（経営判断KPIの割り切り）。
     *
     * @return array{nursery_teacher: int, other: int, total: int}
     */
    public function getSaturdayCapableHeadcount(int $facilityId, string $yearMonth): array
    {
        [$monthStart, $monthEnd] = $this->resolveMonthRange($yearMonth);
        if ($monthStart === null || $monthEnd === null) {
            return ['nursery_teacher' => 0, 'other' => 0, 'total' => 0];
        }

        // 対象月に有効な配属で、is_saturday_target が立つ勤務タイプに就いている職員を集める
        $assignments = EmploeeAssignment::query()
            ->with(['emploee:id,qualification,employment_end_date'])
            ->whereHas('workStyle', fn ($query) => $query->where('is_saturday_target', true))
            ->where('facility_id', $facilityId)
            ->whereNotNull('work_style_id')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($monthStart): void {
                $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $monthStart->toDateString());
            })
            ->whereHas('emploee', function ($query) use ($monthStart, $monthEnd): void {
                $query
                    ->whereDate('employment_start_date', '<=', $monthEnd->toDateString())
                    ->where(function ($dateQuery) use ($monthStart): void {
                        $dateQuery
                            ->whereNull('employment_end_date')
                            ->orWhereDate('employment_end_date', '>=', $monthStart->toDateString());
                    });
            })
            ->get(['id', 'staff_id', 'work_style_id', 'facility_id']);

        $counts = ['nursery_teacher' => 0, 'other' => 0];
        foreach ($assignments as $assignment) {
            $qualification = trim((string) ($assignment->emploee?->qualification ?? ''));
            if (in_array($qualification, ['nursery_teacher', 'other'], true)) {
                $counts[$qualification]++;
            }
        }
        $counts['total'] = $counts['nursery_teacher'] + $counts['other'];

        return $counts;
    }

    // ============================================================
    // 以下、AllocationController から移したプライベート計算ロジック群
    // （挙動は1ミリも変えていない・忠実な移動）
    // ============================================================

    /**
     * open〜close を30分スロットに分割
     * 返り値：[['start'=>'07:00','end'=>'07:30'], ...]
     */
    private function buildSlots(?string $open, ?string $close): array
    {
        // open/close 未設定時は既定の開所時間を使う
        $open = $open ?: '07:00';
        $close = $close ?: '19:00';

        $s = $this->timeToMinutes(substr($open, 0, 5));
        $e = $this->timeToMinutes(substr($close, 0, 5));

        // 保険：開所>=閉所なら既定に戻す
        if ($s >= $e) {
            $s = $this->timeToMinutes('07:00');
            $e = $this->timeToMinutes('19:00');
        }

        $slots = [];
        for ($t = $s; $t < $e; $t += 30) {
            $slots[] = [
                'start' => $this->minutesToTime($t),
                'end' => $this->minutesToTime($t + 30),
            ];
        }
        return $slots;
    }

    /**
     * 表示用の行を作る。
     * 実人数は actualOverrides（=平日の自動算出結果）から取る。
     * （土曜マトリクスを廃止した2026-06-01以降、本メソッドは平日のみで使用）
     */
    private function buildMatrixRows(
        string $dayType,
        array $slots,
        Collection $reqs,
        array $actualOverrides
    ): array {
        $rows = [];
        foreach ($slots as $slot) {
            $key = $this->slotKey($dayType, $slot['start'], $slot['end']);

            $req = $reqs->get($key);

            $reqNursery = (int) ($req->required_nursery_teacher_count ?? 0);
            $reqOther   = (int) ($req->required_other_staff_count ?? 0);

            $actNursery = (int) ($actualOverrides[$key]['actual_nursery_teacher_count'] ?? 0);
            $actOther = (int) ($actualOverrides[$key]['actual_other_staff_count'] ?? 0);

            $diffNursery = $reqNursery - $actNursery;
            $diffOther   = $reqOther - $actOther;
            $diffTotal   = ($reqNursery + $reqOther) - ($actNursery + $actOther);

            $rows[] = [
                'day_type' => $dayType,
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],

                'required_nursery_teacher_count' => $reqNursery,
                'required_other_staff_count' => $reqOther,

                'actual_nursery_teacher_count' => $actNursery,
                'actual_other_staff_count' => $actOther,

                'diff_nursery' => $diffNursery,
                'diff_other' => $diffOther,
                'diff_total' => $diffTotal,
            ];
        }
        return $rows;
    }

    /**
     * 平日の実数を職員配属 + 勤務タイプ定義から自動算出する。
     *
     * @return array{0: array<string, array<string, int>>, 1: array<int, string>}
     */
    private function buildWeekdayAutoActualsAndAlerts(int $facilityId, string $yearMonth): array
    {
        // 対象月の開始日・終了日。ここを基準に在籍期間・配属期間を絞る。
        [$monthStart, $monthEnd] = $this->resolveMonthRange($yearMonth);
        if ($monthStart === null || $monthEnd === null) {
            return [[], []];
        }

        // 1) 対象月に有効な配属を取得（施設一致・配属期間一致・在籍期間一致）
        $assignments = EmploeeAssignment::query()
            ->with([
                'emploee:id,qualification,employment_end_date',
                'workStyle:id,name,master_key,style_type',
                'workStyle.timeSlots:id,work_style_id,start_time,end_time,slot_order',
            ])
            ->where('facility_id', $facilityId)
            ->whereNotNull('work_style_id')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($monthStart): void {
                $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $monthStart->toDateString());
            })
            ->whereHas('emploee', function ($query) use ($monthStart, $monthEnd): void {
                $query
                    ->whereDate('employment_start_date', '<=', $monthEnd->toDateString())
                    ->where(function ($dateQuery) use ($monthStart): void {
                        $dateQuery
                            ->whereNull('employment_end_date')
                            ->orWhereDate('employment_end_date', '>=', $monthStart->toDateString());
                    });
            })
            ->get([
                'staff_id',
                'work_style_id',
            ]);

        if ($assignments->isEmpty()) {
            return [[], []];
        }

        $actuals = [];
        $alerts = [];

        // --- 固定勤務とローテーション勤務を分離 ---
        $fixedAssignments = [];
        $rotationAssignments = [];

        foreach ($assignments as $assignment) {
            $styleType = trim((string) ($assignment->workStyle?->style_type ?? ''));
            if ($styleType === 'rotation') {
                $rotationAssignments[] = $assignment;
            } else {
                $fixedAssignments[] = $assignment;
            }
        }

        // ============================================================
        // A) 固定勤務：職員数をそのまま時間枠に展開（required_counts 不要）
        // ============================================================
        foreach ($fixedAssignments as $assignment) {
            $qualification = trim((string) ($assignment->emploee?->qualification ?? ''));
            if (!in_array($qualification, ['nursery_teacher', 'other'], true)) {
                continue;
            }

            $timeSlots = $assignment->workStyle?->timeSlots ?? collect();
            if ($timeSlots->isEmpty()) {
                continue;
            }

            foreach ($timeSlots as $timeSlot) {
                $expandedKeys = $this->expandToHalfHourKeys(
                    (string) $timeSlot->start_time,
                    (string) $timeSlot->end_time,
                    'weekday'
                );

                foreach ($expandedKeys as $key) {
                    if (!isset($actuals[$key])) {
                        $actuals[$key] = [
                            'actual_nursery_teacher_count' => 0,
                            'actual_other_staff_count' => 0,
                        ];
                    }

                    if ($qualification === 'nursery_teacher') {
                        $actuals[$key]['actual_nursery_teacher_count'] += 1;
                    } else {
                        $actuals[$key]['actual_other_staff_count'] += 1;
                    }
                }
            }
        }

        // ============================================================
        // B) ローテーション勤務：SlotRequirement + 調整枠ロジック
        // ============================================================
        if (count($rotationAssignments) === 0) {
            return [$actuals, array_values(array_unique($alerts))];
        }

        $rotationMasterKeys = collect($rotationAssignments)
            ->map(fn (EmploeeAssignment $a): string => $this->resolveWorkStyleMasterKey($a))
            ->filter(static fn (string $k): bool => $k !== '')
            ->unique()
            ->values();
        if ($rotationMasterKeys->isEmpty()) {
            return [$actuals, array_values(array_unique($alerts))];
        }

        // 2) 対象月以下の定義を取得し、slot_order + qualification ごとに最新を採用する
        $candidateRequirements = WorkStyleSlotRequirement::query()
            ->whereIn('work_style_master_key', $rotationMasterKeys->all())
            ->where('effective_from_ym', '<=', $yearMonth)
            ->orderBy('work_style_master_key')
            ->orderBy('effective_from_ym')
            ->orderBy('id')
            ->get([
                'id',
                'work_style_master_key',
                'effective_from_ym',
                'slot_order',
                'is_adjustable',
                'start_time',
                'end_time',
                'qualification',
                'required_count',
            ]);
        if ($candidateRequirements->isEmpty()) {
            return [$actuals, array_values(array_unique($alerts))];
        }

        $latestRequirementByStyleSlotQualification = [];
        foreach ($candidateRequirements as $requirement) {
            $masterKey = trim((string) $requirement->work_style_master_key);
            $slotOrder = (int) $requirement->slot_order;
            $qualification = trim((string) $requirement->qualification);
            if ($masterKey === '' || $slotOrder <= 0 || $qualification === '') {
                continue;
            }

            $compoundKey = $masterKey.'|'.$slotOrder.'|'.$qualification;
            $latestRequirementByStyleSlotQualification[$compoundKey] = $requirement;
        }
        if ($latestRequirementByStyleSlotQualification === []) {
            return [$actuals, array_values(array_unique($alerts))];
        }

        // [master_key][slot_key][qualification] => required_count
        $requiredByStyleSlotQualification = [];
        // [master_key][slot_key] => ['start_time' => ..., 'end_time' => ..., ...]
        $slotsByStyleMasterKey = [];
        foreach ($latestRequirementByStyleSlotQualification as $requirement) {
            $masterKey = trim((string) $requirement->work_style_master_key);
            if ($masterKey === '') {
                continue;
            }

            $qualification = trim((string) $requirement->qualification);
            if (!in_array($qualification, ['nursery_teacher', 'other'], true)) {
                continue;
            }

            $slotOrder = (int) $requirement->slot_order;
            $startTime = (string) $requirement->start_time;
            $endTime = (string) $requirement->end_time;
            if ($slotOrder <= 0 || trim($startTime) === '' || trim($endTime) === '') {
                continue;
            }

            $slotKey = $slotOrder.'|'.$startTime.'|'.$endTime;
            $slotsByStyleMasterKey[$masterKey][$slotKey] = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_adjustable' => (bool) $requirement->is_adjustable,
                'slot_order' => $slotOrder,
            ];
            $requiredByStyleSlotQualification[$masterKey][$slotKey][$qualification]
                = (int) $requirement->required_count;
        }
        if ($slotsByStyleMasterKey === []) {
            return [$actuals, array_values(array_unique($alerts))];
        }

        $styleNameByMasterKey = [];
        $staffCountByStyleQualification = [];

        // 4) ローテーション勤務タイプ×資格ごとの登録職員数を集計
        foreach ($rotationAssignments as $assignment) {
            $masterKey = $this->resolveWorkStyleMasterKey($assignment);
            if ($masterKey === '' || !isset($slotsByStyleMasterKey[$masterKey])) {
                continue;
            }

            $styleNameByMasterKey[$masterKey] = (string) ($assignment->workStyle?->name ?? ('key:'.$masterKey));

            $qualification = trim((string) ($assignment->emploee?->qualification ?? ''));
            if (!in_array($qualification, ['nursery_teacher', 'other'], true)) {
                continue;
            }
            $staffCountByStyleQualification[$masterKey][$qualification]
                = (int) ($staffCountByStyleQualification[$masterKey][$qualification] ?? 0) + 1;
        }

        // 5) ローテーション勤務タイプごとに実数計算（調整枠対応）
        foreach ($slotsByStyleMasterKey as $masterKey => $styleSlots) {
            $styleName = $styleNameByMasterKey[$masterKey] ?? ('key:'.$masterKey);

            // 調整枠の特定（is_adjustable=true のスロット、未設定時は slot_order 最小）
            $adjustableSlotKey = null;
            $lowestSlotKey = null;
            $lowestSlotOrder = PHP_INT_MAX;

            foreach ($styleSlots as $slotKey => $slot) {
                if (!empty($slot['is_adjustable'])) {
                    $adjustableSlotKey = $slotKey;
                }
                $slotOrder = (int) ($slot['slot_order'] ?? PHP_INT_MAX);
                if ($slotOrder < $lowestSlotOrder) {
                    $lowestSlotOrder = $slotOrder;
                    $lowestSlotKey = $slotKey;
                }
            }

            if ($adjustableSlotKey === null && $lowestSlotKey !== null) {
                $adjustableSlotKey = $lowestSlotKey;

                $adjSlot = $styleSlots[$adjustableSlotKey];
                $alerts[] = sprintf(
                    '勤務タイプ「%s」に調整枠が設定されていません。「%s-%s」枠を調整枠として使用しています',
                    $styleName,
                    substr((string) $adjSlot['start_time'], 0, 5),
                    substr((string) $adjSlot['end_time'], 0, 5)
                );
            }

            foreach (['nursery_teacher', 'other'] as $qualification) {
                $staffCount = (int) ($staffCountByStyleQualification[$masterKey][$qualification] ?? 0);

                $requiredBySlot = [];
                $requiredTotal = 0;
                foreach ($styleSlots as $slotKey => $slot) {
                    $required = (int) ($requiredByStyleSlotQualification[$masterKey][$slotKey][$qualification] ?? 0);
                    $requiredBySlot[$slotKey] = $required;
                    $requiredTotal += $required;
                }

                if ($requiredTotal <= 0 && $staffCount <= 0) {
                    continue;
                }

                $diff = $staffCount - $requiredTotal;

                if ($diff !== 0 && $adjustableSlotKey !== null) {
                    $adjustableRequired = (int) ($requiredBySlot[$adjustableSlotKey] ?? 0);
                    $adjustedCount = $adjustableRequired + $diff;

                    if ($adjustedCount < 0) {
                        $alerts[] = sprintf(
                            '勤務タイプ「%s」(%s)：配属%d名 / 規定%d名 — 調整枠で吸収できません',
                            $styleName,
                            $this->qualificationLabel($qualification),
                            $staffCount,
                            $requiredTotal
                        );
                        continue;
                    }

                    $requiredBySlot[$adjustableSlotKey] = $adjustedCount;

                    $adjSlot = $styleSlots[$adjustableSlotKey];
                    $adjLabel = substr((string) $adjSlot['start_time'], 0, 5).'-'.substr((string) $adjSlot['end_time'], 0, 5);
                    $sign = $diff > 0 ? '+' : '';
                    $alerts[] = sprintf(
                        '勤務タイプ「%s」(%s)：%s枠で %s%d名 調整しています(配属%d名 / 規定%d名)',
                        $styleName,
                        $this->qualificationLabel($qualification),
                        $adjLabel,
                        $sign,
                        $diff,
                        $staffCount,
                        $requiredTotal
                    );
                } elseif ($diff !== 0) {
                    $diffLabel = $diff > 0 ? ($diff.'人超過') : (abs($diff).'人不足');
                    $alerts[] = sprintf(
                        '勤務タイプ「%s」(平日・%s)で割り当て職員が%sしています。',
                        $styleName,
                        $this->qualificationLabel($qualification),
                        $diffLabel
                    );
                    continue;
                }

                foreach ($styleSlots as $slotKey => $slot) {
                    $allocatedCount = (int) ($requiredBySlot[$slotKey] ?? 0);
                    if ($allocatedCount <= 0) {
                        continue;
                    }

                    $expandedKeys = $this->expandToHalfHourKeys(
                        (string) $slot['start_time'],
                        (string) $slot['end_time'],
                        'weekday'
                    );

                    foreach ($expandedKeys as $key) {
                        if (!isset($actuals[$key])) {
                            $actuals[$key] = [
                                'actual_nursery_teacher_count' => 0,
                                'actual_other_staff_count' => 0,
                            ];
                        }

                        if ($qualification === 'nursery_teacher') {
                            $actuals[$key]['actual_nursery_teacher_count'] += $allocatedCount;
                        } else {
                            $actuals[$key]['actual_other_staff_count'] += $allocatedCount;
                        }
                    }
                }
            }
        }

        return [$actuals, array_values(array_unique($alerts))];
    }

    private function resolveWorkStyleMasterKey(EmploeeAssignment $assignment): string
    {
        $masterKey = trim((string) ($assignment->workStyle?->master_key ?? ''));
        if ($masterKey !== '') {
            return $masterKey;
        }

        $styleId = (int) ($assignment->work_style_id ?? 0);

        return $styleId > 0 ? (string) $styleId : '';
    }

    /**
     * 勤務枠（例: 08:30-17:30）を画面の30分スロットキー群へ展開する。
     *
     * @return array<int, string>
     */
    private function expandToHalfHourKeys(string $startTime, string $endTime, string $dayType): array
    {
        $start = $this->timeToMinutes(substr($startTime, 0, 5));
        $end = $this->timeToMinutes(substr($endTime, 0, 5));
        if ($end <= $start) {
            return [];
        }

        $keys = [];
        for ($time = $start; ($time + 30) <= $end; $time += 30) {
            $keys[] = $dayType.'|'.$this->minutesToTime($time).'|'.$this->minutesToTime($time + 30);
        }

        return $keys;
    }

    /**
     * "YYYY-MM" を対象月の [startOfMonth, endOfMonth] に変換。
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function resolveMonthRange(string $yearMonth): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
            return [null, null];
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        } catch (Throwable) {
            return [null, null];
        }

        return [$start, $start->endOfMonth()];
    }

    private function qualificationLabel(string $qualification): string
    {
        return $qualification === 'nursery_teacher' ? '保育士等' : 'その他';
    }

    private function slotKey(string $dayType, string $startTime, string $endTime): string
    {
        return $dayType.'|'.$startTime.'|'.$endTime;
    }

    private function timeToMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }

    private function minutesToTime(int $min): string
    {
        $h = str_pad((string) intdiv($min, 60), 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT);
        return "{$h}:{$m}";
    }
}

<?php

namespace App\Http\Controllers\Evidence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Evidence\ActualsIndexRequest;
use App\Models\Facility;
use App\Models\SubsidyActual;
use App\Models\SubsidyMaster;
use App\Support\EvidenceAddonCatalog;
use App\Support\SubsidyCodes;
use Carbon\Carbon;

class ActualsController extends Controller
{
    /**
     * GET /evidence/actuals
     * 目的：実績（月次 actual_amount）を「表」で表示する
     */
    public function index(ActualsIndexRequest $request)
    {
        $facilityIdParam = $request->query('facility_id');
        $facilities = Facility::orderBy('id')->get();
        $facility = $facilityIdParam
            ? $facilities->firstWhere('id', (int) $facilityIdParam)
            : $facilities->first();
        $facilityId = $facility?->id;

        if (!$facility) {
            return view('evidence.actuals.index', [
                'facility' => null,
                'facilities' => $facilities,
                'facilityId' => null,
                'fiscalYear' => $request->query('fiscal_year') ?: now()->format('Y'),
                'months' => [],
                'codes' => [],
                'values' => [],
                'rowTotals' => [],
                'grandTotal' => 0,
            ]);
        }

        $fiscalYear = $request->query('fiscal_year') ?: now()->format('Y');
        $months = $this->buildFiscalYearMonths((int) $fiscalYear);

        $baseCodes = $this->actualsBaseCodes();
        $allowedBaseCodes = array_fill_keys($baseCodes, true);
        [$masterBaseCodeByCode, $baseDisplayNames] = $this->buildMasterMaps($allowedBaseCodes);

        $values = [];
        foreach ($baseCodes as $baseCode) {
            foreach ($months as $ym) {
                $values[$baseCode][$ym] = 0;
            }
        }

        $rows = SubsidyActual::query()
            ->where('facility_id', $facilityId)
            ->where('fiscal_year', (int) $fiscalYear)
            ->whereIn('year_month', $months)
            ->get(['subsidy_code', 'year_month', 'actual_amount']);

        foreach ($rows as $row) {
            $sourceCode = (string) $row->subsidy_code;
            $baseCode = $masterBaseCodeByCode[$sourceCode] ?? $this->resolveBaseCode($sourceCode);
            if (!isset($allowedBaseCodes[$baseCode])) {
                continue;
            }

            $ym = (string) $row->year_month;
            $values[$baseCode][$ym] = (int) ($values[$baseCode][$ym] ?? 0) + (int) $row->actual_amount;
        }

        $codes = [];
        foreach ($baseCodes as $baseCode) {
            $codes[] = [
                'code' => $baseCode,
                'name' => $baseDisplayNames[$baseCode] ?? $baseCode,
            ];
        }

        $rowTotals = [];
        $grandTotal = 0;
        foreach ($codes as $code) {
            $sum = 0;
            foreach ($months as $ym) {
                $sum += (int) ($values[$code['code']][$ym] ?? 0);
            }
            $rowTotals[$code['code']] = $sum;
            $grandTotal += $sum;
        }

        return view('evidence.actuals.index', [
            'facility' => $facility,
            'facilities' => $facilities,
            'facilityId' => $facilityId,
            'fiscalYear' => (int) $fiscalYear,
            'months' => $months,
            'codes' => $codes,
            'values' => $values,
            'rowTotals' => $rowTotals,
            'grandTotal' => $grandTotal,
        ]);
    }

    /**
     * 年度の4月〜翌3月（YYYY-MM）を作る。
     */
    private function buildFiscalYearMonths(int $fiscalYear): array
    {
        $months = [];
        $start = Carbon::create($fiscalYear, 4, 1)->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        return $months;
    }

    private function buildMasterMaps(array $allowedBaseCodes): array
    {
        $allowedCodes = array_keys($allowedBaseCodes);
        $masters = SubsidyMaster::query()
            ->where(function ($query) use ($allowedCodes): void {
                $query->whereIn('base_code', $allowedCodes)
                    ->orWhereIn('code', $allowedCodes);
            })
            ->orderBy('id')
            ->get(['code', 'base_code', 'name', 'aggregate_label']);

        $masterBaseCodeByCode = [];
        $baseDisplayNames = [];

        foreach ($masters as $master) {
            $code = (string) $master->code;
            $baseCode = $this->resolveBaseCode((string) $master->base_code ?: $code);
            if (!isset($allowedBaseCodes[$baseCode])) {
                continue;
            }

            $masterBaseCodeByCode[$code] = $baseCode;
            if (!isset($baseDisplayNames[$baseCode])) {
                $displayName = trim((string) $master->aggregate_label);
                if ($displayName === '') {
                    $displayName = trim((string) $master->name);
                }
                $baseDisplayNames[$baseCode] = $displayName !== '' ? $displayName : $baseCode;
            }
        }

        return [$masterBaseCodeByCode, $baseDisplayNames];
    }

    private function resolveBaseCode(string $code): string
    {
        return SubsidyCodes::resolveBaseCode($code);
    }

    /**
     * @return array<int, string>
     */
    private function actualsBaseCodes(): array
    {
        $codes = SubsidyCodes::INPUT_LINKED_BASE_CODES;

        // チェックボックス型固定額加算
        foreach (EvidenceAddonCatalog::fixedAmountAddonDefinitions() as $definition) {
            $codes[] = $definition['subsidy_code'];
        }

        // Calculator内で独自計算する加算（3月専用等）
        $codes[] = SubsidyCodes::TREATMENT_IMPROVEMENT_CAT3;
        $codes[] = SubsidyCodes::FACILITY_CAPABILITY_STRENGTHENING;
        $codes[] = SubsidyCodes::THIRD_PARTY_EVALUATION;
        $codes[] = SubsidyCodes::SNOW_REMOVAL;
        $codes[] = SubsidyCodes::ASH_REMOVAL;
        $codes[] = SubsidyCodes::NIGHT_CARE;

        // 系統選択型加算（select addons）
        foreach (EvidenceAddonCatalog::selectAddonDefinitions() as $definition) {
            $codes[] = $definition['ui_code'];
        }

        // 調整部分（減額）
        $codes[] = SubsidyCodes::BRANCH_FACILITY;
        $codes[] = SubsidyCodes::DIRECTOR_NOT_ASSIGNED;
        $codes[] = SubsidyCodes::SATURDAY_CLOSURE;
        $codes[] = SubsidyCodes::CHRONIC_OVER_CAPACITY;

        // 副食費徴収免除加算（pass-through）も閲覧表に表示する
        $codes[] = SubsidyCodes::FOOD_FEE_EXEMPTION;

        return array_values(array_unique($codes));
    }
}

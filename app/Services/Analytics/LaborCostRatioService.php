<?php

namespace App\Services\Analytics;

use App\Models\Payroll;
use App\Models\SubsidyActual;
use Illuminate\Support\Facades\DB;
use App\Support\FiscalYear;
use App\Support\SubsidyCodes;

class LaborCostRatioService
{
    /**
     * 指定施設・指定年度の月次「人件費割合（人件費 ÷ 収入）」データを返す。
     * 当年度と前年度を同じ月並びで集計し、画面で比較できる形にして返す。
     *
     * @return array{
     *     labels: array<int, string>,
     *     ratioCurrent: array<int, float|null>,
     *     ratioPrev: array<int, float|null>,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function getMonthlyStats(int $facilityId, int $fiscalYear): array
    {
        // 当年度・前年度の月並び（4月〜翌3月）を作り、indexを揃えて比較できるようにする
        $months = FiscalYear::months($fiscalYear);
        $prevFiscalYear = $fiscalYear - 1;
        $prevMonths = FiscalYear::months($prevFiscalYear);

        // 人件費（当年度）: payrolls.gross_pay を月ごとに合計 → [年月 => 合計]
        $payrollCurrent = Payroll::query()
            ->where('facility_id', $facilityId)
            ->where('fiscal_year', $fiscalYear)
            ->whereIn('year_month', $months)
            ->groupBy('year_month')
            ->select('year_month', DB::raw('SUM(gross_pay) AS payroll_sum'))
            ->pluck('payroll_sum', 'year_month');

        // 人件費（前年度）
        $payrollPrev = Payroll::query()
            ->where('facility_id', $facilityId)
            ->where('fiscal_year', $prevFiscalYear)
            ->whereIn('year_month', $prevMonths)
            ->groupBy('year_month')
            ->select('year_month', DB::raw('SUM(gross_pay) AS payroll_sum'))
            ->pluck('payroll_sum', 'year_month');

        // 収入（当年度）: subsidy_actuals.actual_amount を月ごとに合計 → [年月 => 合計]
        $incomeCurrent = SubsidyActual::query()
            ->where('facility_id', $facilityId)
            ->where('fiscal_year', $fiscalYear)
            ->whereIn('year_month', $months)
            ->whereNotIn('subsidy_code', SubsidyCodes::PASS_THROUGH_CODES)
            ->groupBy('year_month')
            ->select('year_month', DB::raw('SUM(actual_amount) AS income_sum'))
            ->pluck('income_sum', 'year_month');

        // 収入（前年度）
        $incomePrev = SubsidyActual::query()
            ->where('facility_id', $facilityId)
            ->where('fiscal_year', $prevFiscalYear)
            ->whereIn('year_month', $prevMonths)
            ->whereNotIn('subsidy_code', SubsidyCodes::PASS_THROUGH_CODES)
            ->groupBy('year_month')
            ->select('year_month', DB::raw('SUM(actual_amount) AS income_sum'))
            ->pluck('income_sum', 'year_month');

        // 月ごとに「人件費割合(%)」を計算する（収入が0の月は null = 画面で "-" 表示）
        $ratioCurrent = [];
        $ratioPrev = [];
        $rows = [];

        foreach ($months as $index => $ym) {
            // 当年度の人件費・収入
            $pay = (float) ($payrollCurrent[$ym] ?? 0);
            $inc = (float) ($incomeCurrent[$ym] ?? 0);

            // 前年度（同じindexの月）の人件費・収入
            $prevYm = $prevMonths[$index];
            $payP = (float) ($payrollPrev[$prevYm] ?? 0);
            $incP = (float) ($incomePrev[$prevYm] ?? 0);

            // 割合 = 人件費 ÷ 収入 × 100（小数1位）。収入0なら null
            $r  = ($inc  > 0) ? round(($pay  / $inc)  * 100, 1) : null;
            $rP = ($incP > 0) ? round(($payP / $incP) * 100, 1) : null;

            $ratioCurrent[] = $r;
            $ratioPrev[] = $rP;

            $rows[] = [
                'ym' => $ym,
                'income' => (int) $inc,
                'payroll' => (int) $pay,
                'ratio' => $r,
                'prev_income' => (int) $incP,
                'prev_payroll' => (int) $payP,
                'prev_ratio' => $rP,
            ];
        }

        return [
            'labels' => $months,
            'ratioCurrent' => $ratioCurrent,
            'ratioPrev' => $ratioPrev,
            'rows' => $rows,
        ];
    }
}

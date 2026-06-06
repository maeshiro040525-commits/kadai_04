<?php

namespace App\Services\Evidence;

use App\Models\SubsidyMaster;
use App\Support\SubsidyCodes;
use Illuminate\Support\Facades\Schema;

class SubsidyMasterSyncService
{
    private const DEFAULT_REQUIRES_STAFF_ASSIGNMENT_CODES = [
        SubsidyCodes::TEAM_CARE,
        SubsidyCodes::CAP23_MIN_STAFFING_ASSIGNMENT,
    ];

    /**
     * @param  array<int, string>  $codes
     */
    public function sync(array $codes): void
    {
        if (!Schema::hasTable('subsidy_master')) {
            return;
        }

        $codes = $this->normalizeCodes($codes);
        if ($codes === []) {
            return;
        }

        $baseCodesByCode = [];
        foreach ($codes as $code) {
            $baseCodesByCode[$code] = SubsidyCodes::resolveBaseCode($code);
        }

        $baseCodes = array_values(array_unique(array_values($baseCodesByCode)));
        $candidateCodes = array_values(array_unique(array_merge($codes, $baseCodes)));

        $masters = SubsidyMaster::query()
            ->where(function ($query) use ($candidateCodes, $baseCodes): void {
                $query->whereIn('code', $candidateCodes)
                    ->orWhereIn('base_code', $baseCodes);
            })
            ->orderBy('id')
            ->get(['code', 'base_code', 'name', 'aggregate_label', 'requires_staff_assignment']);

        $masterByCode = [];
        $baseMasterByBaseCode = [];
        foreach ($masters as $master) {
            $masterCode = trim((string) $master->code);
            if ($masterCode !== '' && !array_key_exists($masterCode, $masterByCode)) {
                $masterByCode[$masterCode] = $master;
            }

            $masterBaseCode = trim((string) $master->base_code);
            $lookupBaseCode = $masterBaseCode !== '' ? $masterBaseCode : $masterCode;
            if ($lookupBaseCode !== '' && !array_key_exists($lookupBaseCode, $baseMasterByBaseCode)) {
                $baseMasterByBaseCode[$lookupBaseCode] = $master;
            }
        }

        $now = now();
        $rows = [];
        foreach ($codes as $code) {
            $baseCode = $baseCodesByCode[$code];
            $master = $masterByCode[$code] ?? null;
            $baseMaster = $baseMasterByBaseCode[$baseCode] ?? ($masterByCode[$baseCode] ?? null);

            $name = $this->resolveName(
                $code,
                $master?->name,
                $baseMaster?->name,
                $baseMaster?->aggregate_label
            );
            $aggregateLabel = $this->resolveAggregateLabelValue($name, $baseCode, $master?->aggregate_label);

            $rows[] = [
                'code' => $code,
                'base_code' => $baseCode,
                'name' => $name,
                'aggregate_label' => $aggregateLabel,
                'requires_staff_assignment' => $this->resolveRequiresStaffAssignment(
                    $code,
                    $master?->requires_staff_assignment
                ),
                'is_monthly_toggleable' => true,
                'ti1_ti2_flag' => SubsidyCodes::isTi12Code($code),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SubsidyMaster::query()->upsert(
            $rows,
            ['code'],
            [
                'base_code',
                'name',
                'aggregate_label',
                'requires_staff_assignment',
                'is_monthly_toggleable',
                'ti1_ti2_flag',
                'updated_at',
            ]
        );
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private function normalizeCodes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $value = trim((string) $code);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function resolveName(string $code, mixed $name, mixed $baseName, mixed $baseAggregateLabel): string
    {
        $resolved = trim((string) $name);
        if ($resolved !== '') {
            return $resolved;
        }

        $baseCode = SubsidyCodes::resolveBaseCode($code);
        $resolvedBaseName = trim((string) $baseName);
        $resolvedBaseAggregateLabel = trim((string) $baseAggregateLabel);

        if (SubsidyCodes::isTi12Code($code)) {
            $baseLabel = $resolvedBaseAggregateLabel !== ''
                ? $resolvedBaseAggregateLabel
                : $this->resolveAggregateLabel($resolvedBaseName, $baseCode);

            if ($baseLabel !== '') {
                return str_contains($baseLabel, '（区分１・２）')
                    ? $baseLabel
                    : "{$baseLabel}（区分１・２）";
            }
        } else {
            if ($resolvedBaseName !== '') {
                return $this->resolveAggregateLabel($resolvedBaseName, $baseCode);
            }
            if ($resolvedBaseAggregateLabel !== '') {
                return $resolvedBaseAggregateLabel;
            }
        }

        return $code;
    }

    private function resolveAggregateLabelValue(string $name, string $baseCode, mixed $aggregateLabel): string
    {
        $resolved = trim((string) $aggregateLabel);
        if ($resolved !== '') {
            return $resolved;
        }

        return $this->resolveAggregateLabel($name, $baseCode);
    }

    private function resolveAggregateLabel(string $name, string $baseCode): string
    {
        $label = trim(str_replace('（区分１・２）', '', $name));
        if (str_ends_with($label, SubsidyCodes::TI12_CODE_SUFFIX)) {
            $label = trim(substr($label, 0, -strlen(SubsidyCodes::TI12_CODE_SUFFIX)));
        }

        return $label !== '' ? $label : $baseCode;
    }

    private function resolveRequiresStaffAssignment(string $code, mixed $currentValue): bool
    {
        if ($currentValue !== null) {
            return (bool) $currentValue;
        }

        return in_array($code, self::DEFAULT_REQUIRES_STAFF_ASSIGNMENT_CODES, true);
    }
}

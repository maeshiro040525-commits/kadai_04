<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class EvidenceInputCode extends Model
{
    protected $table = 'evidence_input_codes';

    protected $fillable = [
        'input_code',
        'label',
        'age_code',
        'certification_code',
        'staffing_divisor',
        'staffing_formula_version',
        'effective_year_month',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'staffing_divisor' => 'decimal:4',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function currentCatalogQuery(): Builder
    {
        $model = new static();
        $table = $model->getTable();

        if (!Schema::hasColumn($table, 'effective_year_month')) {
            return static::query()->where('is_active', true);
        }

        $latestRows = static::query()
            ->selectRaw('input_code, MAX(effective_year_month) as effective_year_month')
            ->groupBy('input_code');

        return static::query()
            ->joinSub($latestRows, 'current_evidence_input_code_versions', function ($join) use ($table): void {
                $join
                    ->on($table . '.input_code', '=', 'current_evidence_input_code_versions.input_code')
                    ->on(
                        $table . '.effective_year_month',
                        '=',
                        'current_evidence_input_code_versions.effective_year_month'
                    );
            })
            ->where($table . '.is_active', true)
            ->select($table . '.*');
    }
}

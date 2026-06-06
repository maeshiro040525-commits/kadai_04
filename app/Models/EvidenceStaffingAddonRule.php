<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceStaffingAddonRule extends Model
{
    protected $table = 'evidence_staffing_addon_rules';

    protected $fillable = [
        'addon_ui_code',
        'input_age_codes',
        'basic_item_code',
        'ti_item_code',
        'rate_c_item_code',
        'result_key',
        'result_ti_key',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'input_age_codes' => 'array',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];
}

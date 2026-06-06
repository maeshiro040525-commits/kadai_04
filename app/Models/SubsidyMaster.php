<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubsidyMaster extends Model
{
    protected $table = 'subsidy_master';

    protected $fillable = [
        'code',
        'base_code',
        'name',
        'aggregate_label',
        'requires_staff_assignment',
        'is_monthly_toggleable',
        'is_only_march_toggleable',
        'ti1_ti2_flag',
    ];
}

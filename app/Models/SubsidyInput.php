<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubsidyInput extends Model
{
    protected $table = 'subsidy_inputs';

    protected $fillable = [
        'facility_id',
        'input_scope',
        'fiscal_year',
        'year_month',
        'subsidy_code',
        'is_selected',
        'input_value',
    ];
}


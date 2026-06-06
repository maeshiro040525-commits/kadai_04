<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubsidyActualInput extends Model
{
    protected $table = 'subsidy_actuals_input';

    protected $fillable = [
        'facility_id',
        'fiscal_year',
        'year_month',
        'subsidy_code',
        'is_selected',
        'input_value',
    ];
}

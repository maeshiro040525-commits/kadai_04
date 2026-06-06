<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficialPrice extends Model
{
    protected $table = 'official_prices';

    protected $fillable = [
        'fiscal_year',
        'region_code',
        'facility_type_code',
        'certification_code',
        'capacity_min',
        'capacity_max',
        'age_code',
        'item_code',
        'value',
        'unit',
        'source_column_name',
        'component_key',
    ];
}

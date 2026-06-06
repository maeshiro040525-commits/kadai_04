<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceMonthlyInput extends Model
{
    protected $table = 'evidence_monthly_inputs';

    protected $fillable = [
        'facility_id',
        'year_month',
        'input_code',
        'value',
    ];
}

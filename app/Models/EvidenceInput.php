<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceInput extends Model
{
    protected $table = 'evidence_inputs';

    protected $fillable = [
        'facility_id',
        'input_scope',
        'year_month',
        'input_code',
        'care_duration_type',
        'value',
    ];
}


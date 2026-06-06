<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilitySetting extends Model
{
    protected $fillable = [
        'facility_id',
        'fiscal_year',
        'region_code',
        'capacity',
        'is_branch',
        'facility_type',
        'open_time',
        'close_time',
        'boundary_morning',
        'boundary_core',
        'boundary_evening',
    ];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}

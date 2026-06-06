<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    // DBに保存してOKな項目（安全のため）
    protected $fillable = [
        'corporate_id',
        'name',
        'facility_code',
        'region_code',
        'address',
        'phone_number',
        'capacity',
        'is_branch',
        'facility_type',
        'open_time',
        'close_time',
    ];

    /**
     * この園が属する法人
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * 年度設定（1園に複数年度）
     */
    public function settings(): HasMany
    {
        return $this->hasMany(FacilitySetting::class);
    }

    public function staffExternalCodes(): HasMany
    {
        return $this->hasMany(FacilityStaffExternalCode::class);
    }
}

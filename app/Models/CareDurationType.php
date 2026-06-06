<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareDurationType extends Model
{
    protected $fillable = ['code', 'label', 'facility_type', 'display_order'];

    public static function forFacilityType(string $facilityType): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('facility_type', $facilityType)
            ->orderBy('display_order')
            ->get();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkStyleTimeSlot extends Model
{
    protected $fillable = [
        'work_style_id',
        'start_time',
        'end_time',
        'slot_order',
        'is_adjustable',
    ];

    protected $casts = [
        'slot_order' => 'integer',
        'is_adjustable' => 'boolean',
    ];

    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class);
    }
}

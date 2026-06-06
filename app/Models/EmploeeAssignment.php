<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class EmploeeAssignment extends Model
{
    protected $table = 'emploee_assignments';

    protected $fillable = [
        'staff_id',
        'facility_id',
        'work_style_id',
        'start_date',
        'end_date',
        'leave_start_date',
        'leave_end_date',
        'employment_type',
        'work_style',
        'fte',
        'is_active',
    ];

    protected $casts = [
        'work_style_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'leave_start_date' => 'date',
        'leave_end_date' => 'date',
        'fte' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $assignment->validateDateRange();
            $assignment->validateWorkStyleOwnership();
            $assignment->validateNoOverlappingPeriods();
        });
    }

    public function emploee(): BelongsTo
    {
        return $this->belongsTo(Emploee::class, 'staff_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class, 'work_style_id');
    }

    public function workStyleTimeSlots(): HasMany
    {
        return $this->hasMany(WorkStyleTimeSlot::class, 'work_style_id', 'work_style_id');
    }

    private function validateDateRange(): void
    {
        if ($this->start_date === null) {
            throw ValidationException::withMessages([
                'start_date' => '開始日は必須です。',
            ]);
        }

        if ($this->end_date !== null && $this->end_date->lt($this->start_date)) {
            throw ValidationException::withMessages([
                'end_date' => '終了日は開始日以降で入力してください。',
            ]);
        }

        if ($this->leave_end_date !== null && $this->leave_start_date === null) {
            throw ValidationException::withMessages([
                'leave_start_date' => '休職終了日を入力する場合は休職開始日も必須です。',
            ]);
        }

        if ($this->leave_start_date !== null && $this->leave_end_date !== null && $this->leave_end_date->lt($this->leave_start_date)) {
            throw ValidationException::withMessages([
                'leave_end_date' => '休職終了日は休職開始日以降で入力してください。',
            ]);
        }
    }

    private function validateNoOverlappingPeriods(): void
    {
        if ($this->staff_id === null || $this->facility_id === null || $this->start_date === null) {
            return;
        }

        $newStartDate = $this->start_date->toDateString();
        $newEndDate = $this->end_date?->toDateString() ?? '9999-12-31';

        $overlapQuery = static::query()
            ->where('staff_id', $this->staff_id)
            ->where('facility_id', $this->facility_id)
            ->whereDate('start_date', '<=', $newEndDate)
            ->where(function (Builder $query) use ($newStartDate) {
                $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $newStartDate);
            });

        if ($this->exists) {
            $overlapQuery->whereKeyNot($this->getKey());
        }

        if ($overlapQuery->exists()) {
            throw ValidationException::withMessages([
                'start_date' => '同一職員・同一施設で期間が重複する配属が既に存在します。',
            ]);
        }
    }

    private function validateWorkStyleOwnership(): void
    {
        if ($this->work_style_id === null) {
            return;
        }

        $workStyle = WorkStyle::query()
            ->select(['id', 'name', 'corporate_id'])
            ->find($this->work_style_id);

        if ($workStyle === null) {
            throw ValidationException::withMessages([
                'work_style_id' => '指定された勤務スタイルは存在しません。',
            ]);
        }

        $employeeCorporateId = Emploee::query()
            ->whereKey($this->staff_id)
            ->value('corporate_id');

        if ($employeeCorporateId !== null && (int) $workStyle->corporate_id !== (int) $employeeCorporateId) {
            throw ValidationException::withMessages([
                'work_style_id' => '勤務スタイルの法人が職員所属法人と一致しません。',
            ]);
        }

        // 旧文字列カラムを使う既存画面への互換性のため同期する。
        $this->work_style = $workStyle->name;
    }
}

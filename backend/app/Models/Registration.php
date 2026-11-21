<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Registration extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const DROPPED = 'dropped';
    public const WAITLISTED = 'waitlisted';

    /** Statuses that hold a seat in the section. */
    public const SEAT_HOLDING = [self::PENDING, self::APPROVED];

    protected $fillable = [
        'course_section_id', 'student_id', 'status', 'approved_by', 'note', 'decided_at',
        'waitlisted_at', 'is_retake', 'forced',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'waitlisted_at' => 'datetime',
            'is_retake' => 'boolean',
            'forced' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function result(): HasOne
    {
        return $this->hasOne(Result::class);
    }

    public function scopeHoldingSeat(Builder $query): Builder
    {
        return $query->whereIn('status', self::SEAT_HOLDING);
    }
}

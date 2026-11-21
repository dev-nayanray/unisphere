<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    protected $fillable = ['course_section_id', 'created_by', 'date', 'topic', 'qr_token', 'qr_expires_at'];

    protected $hidden = ['qr_token'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'qr_expires_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function qrIsValid(): bool
    {
        return $this->qr_token !== null && $this->qr_expires_at?->isFuture();
    }
}

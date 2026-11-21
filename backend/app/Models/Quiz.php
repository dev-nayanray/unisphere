<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'course_section_id', 'author_id', 'title', 'instructions', 'category', 'duration_minutes',
        'starts_at', 'ends_at', 'shuffle_questions', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'shuffle_questions' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function maxMarks(): float
    {
        return (float) $this->questions()->sum('marks');
    }

    public function isOpen(): bool
    {
        return $this->published_at !== null
            && ($this->starts_at === null || now()->gte($this->starts_at))
            && ($this->ends_at === null || now()->lte($this->ends_at));
    }
}

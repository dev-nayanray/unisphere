<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    /** Grading categories an assignment can count towards. */
    public const CATEGORIES = ['assignment', 'quiz', 'mid', 'final'];

    protected $fillable = [
        'course_section_id', 'author_id', 'title', 'instructions', 'topic', 'category',
        'max_marks', 'due_at', 'allow_late', 'attachment_path', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
            'due_at' => 'datetime',
            'allow_late' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function isPastDue(): bool
    {
        return $this->due_at !== null && now()->greaterThan($this->due_at);
    }
}

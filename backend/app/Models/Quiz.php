<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    public const RESULTS_IMMEDIATELY = 'immediately';
    public const RESULTS_AFTER_CLOSE = 'after_close';
    public const RESULTS_MANUAL = 'manual';

    protected $fillable = [
        'course_section_id', 'author_id', 'topic_id', 'title', 'instructions', 'category', 'duration_minutes',
        'starts_at', 'ends_at', 'shuffle_questions', 'shuffle_options', 'show_results', 'results_released_at',
        'draw_count', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'results_released_at' => 'datetime',
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'draw_count' => 'integer',
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

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /** Total of all questions. With draw_count, each attempt stores its own max_score. */
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

    public function isClosed(): bool
    {
        return $this->ends_at !== null && now()->gt($this->ends_at);
    }

    /** May students see their score and the correct answers yet? */
    public function resultsVisible(): bool
    {
        return match ($this->show_results) {
            self::RESULTS_AFTER_CLOSE => $this->isClosed(),
            self::RESULTS_MANUAL => $this->results_released_at !== null,
            default => true,
        };
    }
}

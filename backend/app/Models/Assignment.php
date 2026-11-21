<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    /** Grading categories an assignment can count towards. */
    public const CATEGORIES = ['assignment', 'quiz', 'mid', 'final'];

    public const LATE_ACCEPT = 'accept';
    public const LATE_PENALTY = 'penalty';
    public const LATE_REJECT = 'reject';

    protected $fillable = [
        'course_section_id', 'author_id', 'topic_id', 'title', 'instructions', 'category',
        'max_marks', 'due_at', 'late_policy', 'late_penalty_percent', 'assign_to',
        'attachment_path', 'published_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
            'due_at' => 'datetime',
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
            'late_penalty_percent' => 'integer',
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

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssignmentAttachment::class);
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('position');
    }

    /** Students it is assigned to when assign_to = selected. */
    public function assignedStudents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'assignment_student', 'assignment_id', 'student_id');
    }

    /** Published and its publish time has come (a future published_at means scheduled). */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /** Assignments a student can see: published, and for the whole class or chosen for them. */
    public function scopeVisibleToStudent(Builder $query, User $student): Builder
    {
        return $query->published()->where(fn ($q) => $q
            ->where('assign_to', 'all')
            ->orWhereHas('assignedStudents', fn ($q) => $q->where('users.id', $student->id)));
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now());
    }

    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    public function isAssignedTo(User $student): bool
    {
        return $this->assign_to === 'all' || $this->assignedStudents()->where('users.id', $student->id)->exists();
    }

    public function isPastDue(): bool
    {
        return $this->due_at !== null && now()->greaterThan($this->due_at);
    }

    public function acceptsLateWork(): bool
    {
        return $this->late_policy !== self::LATE_REJECT;
    }

    /** Whole or part days late, e.g. 2 hours late = 1 day. */
    public function daysLate(Submission $submission): int
    {
        if (! $this->due_at || ! $submission->submitted_at || $submission->submitted_at->lte($this->due_at)) {
            return 0;
        }

        return (int) ceil($this->due_at->diffInMinutes($submission->submitted_at) / 1440);
    }

    /** Percentage taken off for lateness (0-100). */
    public function latePenaltyPercent(Submission $submission): int
    {
        if ($this->late_policy !== self::LATE_PENALTY) {
            return 0;
        }

        return min(100, $this->daysLate($submission) * $this->late_penalty_percent);
    }

    /** Marks after the late penalty; this is what the gradebook uses. */
    public function effectiveMarks(Submission $submission): ?float
    {
        if ($submission->marks === null) {
            return null;
        }

        return round((float) $submission->marks * (100 - $this->latePenaltyPercent($submission)) / 100, 2);
    }

    /** Highest possible rubric total, or null if the assignment has no rubric. */
    public function rubricMax(): ?float
    {
        $this->loadMissing('criteria.levels');

        if ($this->criteria->isEmpty()) {
            return null;
        }

        return (float) $this->criteria->sum(fn ($c) => $c->levels->max('points'));
    }
}

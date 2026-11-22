<?php

namespace App\Models;

use App\Services\QuizGrader;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class QuizAttempt extends Model
{
    /** Seconds allowed after the deadline for a submit that is already on its way. */
    public const GRACE_SECONDS = 30;

    public const INTEGRITY_EVENTS = ['blur', 'copy', 'paste', 'fullscreen_exit'];

    protected $fillable = [
        'quiz_id', 'student_id', 'answers', 'score', 'started_at', 'submitted_at',
        'question_ids', 'max_score', 'results', 'integrity', 'last_saved_at', 'auto_submitted',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'question_ids' => 'array',
            'results' => 'array',
            'integrity' => 'array',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_saved_at' => 'datetime',
            'auto_submitted' => 'boolean',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** This attempt's questions in the order the student saw them. */
    public function questions(): Collection
    {
        $all = $this->quiz->questions()->get()->keyBy('id');

        if (! $this->question_ids) {
            return $all->values();
        }

        return collect($this->question_ids)->map(fn ($id) => $all->get($id))->filter()->values();
    }

    /**
     * When time runs out, decided by the server only: the time limit from the
     * moment the student started, but never later than the quiz closing.
     */
    public function deadline(): CarbonInterface
    {
        $deadline = $this->started_at->copy()->addMinutes($this->quiz->duration_minutes);

        if ($this->quiz->ends_at && $this->quiz->ends_at->lt($deadline)) {
            return $this->quiz->ends_at->copy();
        }

        return $deadline;
    }

    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->deadline(), false));
    }

    public function isExpired(int $grace = 0): bool
    {
        return now()->greaterThan($this->deadline()->copy()->addSeconds($grace));
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /** Grade the given (or saved) answers and close the attempt. */
    public function finalize(QuizGrader $grader, ?array $answers = null, bool $auto = false): void
    {
        $answers ??= $this->answers ?? [];
        $graded = $grader->gradeAll($this->questions()->each(fn ($q) => $q->withAnswers()), $answers);

        $this->update([
            'answers' => $answers,
            'results' => $graded['results'],
            'score' => $graded['score'],
            'submitted_at' => now(),
            'auto_submitted' => $auto,
        ]);
    }

    /** Recalculate the total from per-question results (after the teacher marks written answers). */
    public function recalculateScore(): void
    {
        $results = collect($this->results ?? []);
        $pending = $results->contains(fn ($r) => $r['score'] === null);

        $this->update(['score' => $pending ? null : round($results->sum('score'), 2)]);
    }

    /** Count of each kind of integrity event, for the teacher. */
    public function integritySummary(): array
    {
        return collect(self::INTEGRITY_EVENTS)->mapWithKeys(fn ($type) => [$type => (int) ($this->integrity[$type] ?? 0)])->all();
    }
}

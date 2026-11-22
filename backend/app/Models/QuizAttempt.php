<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class QuizAttempt extends Model
{
    protected $fillable = [
        'quiz_id', 'student_id', 'answers', 'score', 'started_at', 'submitted_at',
        'question_ids', 'max_score', 'results',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'question_ids' => 'array',
            'results' => 'array',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
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
}

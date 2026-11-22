<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reusable question saved for a course (shared across sections and semesters). */
class QuestionBankItem extends Model
{
    protected $table = 'question_bank';

    protected $fillable = [
        'course_id', 'author_id', 'type', 'question', 'options', 'correct_option', 'correct_options',
        'correct_numeric', 'tolerance', 'marks', 'explanation', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_options' => 'array',
            'correct_numeric' => 'float',
            'tolerance' => 'float',
            'marks' => 'decimal:2',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Fields to copy into a quiz question. */
    public function toQuestion(): array
    {
        return collect($this->only([
            'type', 'question', 'options', 'correct_option', 'correct_options', 'correct_numeric', 'tolerance', 'marks', 'explanation',
        ]))->all();
    }
}

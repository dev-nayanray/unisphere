<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    public const SINGLE = 'single';
    public const MULTIPLE = 'multiple';
    public const TRUE_FALSE = 'true_false';
    public const SHORT = 'short';
    public const NUMERIC = 'numeric';

    public const TYPES = [self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::SHORT, self::NUMERIC];

    /** The answer-key fields, shared with QuestionBankItem. */
    public const KEY_FIELDS = ['correct_option', 'correct_options', 'correct_numeric', 'tolerance', 'explanation'];

    protected $fillable = [
        'quiz_id', 'type', 'question', 'options', 'correct_option', 'correct_options',
        'correct_numeric', 'tolerance', 'marks', 'position', 'explanation',
    ];

    /** Never send the answer key to students. */
    protected $hidden = ['correct_option', 'correct_options', 'correct_numeric', 'tolerance', 'explanation'];

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

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function withAnswers(): static
    {
        return $this->makeVisible(self::KEY_FIELDS);
    }

    public function needsManualGrading(): bool
    {
        return $this->type === self::SHORT;
    }

    /** Question data a student sees: options as [{index, text}], optionally shuffled. */
    public function forStudent(bool $shuffleOptions = false): array
    {
        $options = collect($this->options ?? [])->map(fn ($text, $index) => ['index' => $index, 'text' => $text]);
        if ($shuffleOptions && $this->type !== self::TRUE_FALSE) {
            $options = $options->shuffle();
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'question' => $this->question,
            'options' => $options->values()->all(),
            'marks' => (float) $this->marks,
        ];
    }
}

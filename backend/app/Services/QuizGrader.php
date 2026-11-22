<?php

namespace App\Services;

use App\Models\QuizQuestion;

/**
 * Auto-grades one answer. Returns ['score' => float|null, 'correct' => bool|null].
 * score = null means the question needs the teacher (short answers).
 *
 *   single / true_false  answer is the option index
 *   multiple             answer is a list of option indexes; full marks only for the exact set
 *   numeric              answer is a number; correct within +/- tolerance
 *   short                graded by the teacher
 */
class QuizGrader
{
    public function grade(QuizQuestion $question, mixed $answer): array
    {
        $marks = (float) $question->marks;

        if ($question->type === QuizQuestion::SHORT) {
            return ['score' => null, 'correct' => null];
        }

        if ($answer === null || $answer === '' || $answer === []) {
            return ['score' => 0.0, 'correct' => false];
        }

        $correct = match ($question->type) {
            QuizQuestion::SINGLE, QuizQuestion::TRUE_FALSE => is_numeric($answer) && (int) $answer === (int) $question->correct_option,
            QuizQuestion::MULTIPLE => $this->sameSet((array) $answer, (array) $question->correct_options),
            QuizQuestion::NUMERIC => is_numeric($answer)
                && abs((float) $answer - (float) $question->correct_numeric) <= (float) ($question->tolerance ?? 0) + 1e-9,
            default => false,
        };

        return ['score' => $correct ? $marks : 0.0, 'correct' => $correct];
    }

    /**
     * Grade a whole attempt.
     *
     * @param  iterable<QuizQuestion>  $questions
     * @param  array<int|string, mixed>  $answers  question id => answer
     * @return array{score: float|null, auto_score: float, results: array<int, array>}
     */
    public function gradeAll(iterable $questions, array $answers): array
    {
        $results = [];
        $auto = 0.0;
        $manual = false;

        foreach ($questions as $question) {
            $result = $this->grade($question, $answers[$question->id] ?? null);
            $results[$question->id] = $result;

            if ($result['score'] === null) {
                $manual = true;
            } else {
                $auto += $result['score'];
            }
        }

        return ['score' => $manual ? null : round($auto, 2), 'auto_score' => round($auto, 2), 'results' => $results];
    }

    private function sameSet(array $a, array $b): bool
    {
        $normalise = function (array $values): array {
            $values = array_values(array_unique(array_map('intval', $values)));
            sort($values);

            return $values;
        };

        return $normalise($a) === $normalise($b);
    }
}

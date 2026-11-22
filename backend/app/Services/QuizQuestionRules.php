<?php

namespace App\Services;

use App\Models\QuizQuestion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation for questions, shared by the quiz builder, the question bank and
 * the CSV import, so a question is valid (or not) in exactly the same way everywhere.
 */
class QuizQuestionRules
{
    /** Laravel rules for an array of questions under $prefix (e.g. "questions"). */
    public static function rules(string $prefix = 'questions'): array
    {
        return [
            "{$prefix}.*.type" => ['required', Rule::in(QuizQuestion::TYPES)],
            "{$prefix}.*.question" => ['required', 'string', 'max:5000'],
            "{$prefix}.*.options" => ['nullable', 'array', 'max:8'],
            "{$prefix}.*.options.*" => ['required', 'string', 'max:1000'],
            "{$prefix}.*.correct_option" => ['nullable', 'integer', 'min:0'],
            "{$prefix}.*.correct_options" => ['nullable', 'array'],
            "{$prefix}.*.correct_options.*" => ['integer', 'min:0'],
            "{$prefix}.*.correct_numeric" => ['nullable', 'numeric'],
            "{$prefix}.*.tolerance" => ['nullable', 'numeric', 'min:0'],
            "{$prefix}.*.marks" => ['required', 'numeric', 'min:0.25', 'max:100'],
            "{$prefix}.*.explanation" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Checks that depend on the type (an MCQ needs options and a valid key, etc.).
     * Also normalises: true/false always gets the options ["True", "False"].
     *
     * @param  array<int, array>  $questions
     * @return array<int, array>  cleaned questions
     */
    public static function check(array $questions, string $prefix = 'questions'): array
    {
        $errors = [];

        foreach ($questions as $i => &$q) {
            $key = "{$prefix}.{$i}";
            $options = array_values($q['options'] ?? []);

            switch ($q['type']) {
                case QuizQuestion::TRUE_FALSE:
                    $q['options'] = ['True', 'False'];
                    if (! in_array((int) ($q['correct_option'] ?? -1), [0, 1], true)) {
                        $errors["{$key}.correct_option"] = 'Choose True or False as the answer.';
                    }
                    break;

                case QuizQuestion::SINGLE:
                    if (count($options) < 2) {
                        $errors["{$key}.options"] = 'Add at least two options.';
                    } elseif (! isset($q['correct_option']) || (int) $q['correct_option'] >= count($options)) {
                        $errors["{$key}.correct_option"] = 'Mark the correct option.';
                    }
                    $q['options'] = $options;
                    $q['correct_options'] = null;
                    break;

                case QuizQuestion::MULTIPLE:
                    $correct = array_values(array_unique(array_map('intval', $q['correct_options'] ?? [])));
                    if (count($options) < 2) {
                        $errors["{$key}.options"] = 'Add at least two options.';
                    } elseif ($correct === [] || max($correct) >= count($options)) {
                        $errors["{$key}.correct_options"] = 'Mark at least one correct option.';
                    }
                    $q['options'] = $options;
                    $q['correct_options'] = $correct;
                    $q['correct_option'] = null;
                    break;

                case QuizQuestion::NUMERIC:
                    if (! isset($q['correct_numeric']) || ! is_numeric($q['correct_numeric'])) {
                        $errors["{$key}.correct_numeric"] = 'Enter the correct number.';
                    }
                    $q['options'] = null;
                    $q['tolerance'] = $q['tolerance'] ?? 0;
                    break;

                case QuizQuestion::SHORT:
                    $q['options'] = null;
                    $q['correct_option'] = null;
                    $q['correct_options'] = null;
                    break;
            }
        }
        unset($q);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $questions;
    }

    /** Validate + check in one go, for data that didn't come through a Request. */
    public static function validate(array $questions): array
    {
        $data = Validator::make(['questions' => $questions], ['questions' => ['array'], ...self::rules()])->validate();

        return self::check($data['questions'] ?? []);
    }
}

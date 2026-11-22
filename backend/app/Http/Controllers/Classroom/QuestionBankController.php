<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\QuestionBankItem;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\QuizQuestionRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A question bank per course, plus the CSV template and import for questions. */
class QuestionBankController extends Controller
{
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageQuestionBank', $course);

        $items = QuestionBankItem::where('course_id', $course->id)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->tag, fn ($q, $t) => $q->where('tags', 'like', "%{$t}%"))
            ->when($request->search, fn ($q, $s) => $q->where('question', 'like', "%{$s}%"))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($items);
    }

    /** Save questions to the bank (from the builder: "Save to bank"). */
    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageQuestionBank', $course);

        $data = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:200'],
            ...QuizQuestionRules::rules(),
            'tags' => ['nullable', 'string', 'max:255'],
        ]);
        $questions = QuizQuestionRules::check($data['questions']);

        DB::transaction(function () use ($questions, $course, $request, $data) {
            foreach ($questions as $q) {
                QuestionBankItem::create([
                    ...collect($q)->only(['type', 'question', 'options', 'correct_option', 'correct_options', 'correct_numeric', 'tolerance', 'marks', 'explanation'])->all(),
                    'course_id' => $course->id,
                    'author_id' => $request->user()->id,
                    'tags' => $data['tags'] ?? null,
                ]);
            }
        });

        return response()->json(['message' => count($questions).' question(s) saved to the '.$course->code.' bank.'], 201);
    }

    public function destroy(QuestionBankItem $item): JsonResponse
    {
        Gate::authorize('manageQuestionBank', $item->course);
        $item->delete();

        return response()->json(['message' => 'Removed from the question bank.']);
    }

    /**
     * Questions from the bank, ready to drop into the builder (with answers).
     * Either chosen ids, or N random ones (optionally by type/tag).
     */
    public function pick(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageQuestionBank', $course);

        $data = $request->validate([
            'ids' => ['required_without:random', 'array'],
            'ids.*' => ['integer'],
            'random' => ['required_without:ids', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'string'],
            'tag' => ['nullable', 'string'],
        ]);

        $query = QuestionBankItem::where('course_id', $course->id)
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['tag'] ?? null, fn ($q, $t) => $q->where('tags', 'like', "%{$t}%"));

        $items = isset($data['ids'])
            ? $query->whereIn('id', $data['ids'])->get()
            : $query->inRandomOrder()->limit($data['random'])->get();

        return response()->json($items->map->toQuestion()->values());
    }

    public function template(): StreamedResponse
    {
        $rows = [
            ['type', 'question', 'option1', 'option2', 'option3', 'option4', 'option5', 'option6', 'correct', 'marks', 'tolerance', 'explanation'],
            ['single', 'What is 2 + 2?', '3', '4', '5', '', '', '', '2', '1', '', 'Basic addition'],
            ['multiple', 'Which are prime?', '2', '4', '5', '9', '', '', '1;3', '2', '', '2 and 5 are prime'],
            ['true_false', 'HTTP is stateless.', '', '', '', '', '', '', 'true', '1', '', ''],
            ['numeric', 'Value of pi to 2 decimals?', '', '', '', '', '', '', '3.14', '1', '0.01', ''],
            ['short', 'Explain normalisation in one sentence.', '', '', '', '', '', '', '', '3', '', ''],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'unisphere-quiz-questions-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Parse a questions CSV and return the questions for the builder (nothing is saved).
     * "correct" is 1-based for options (e.g. 2, or 1;3 for multiple), true/false for
     * true_false, a number for numeric. Errors are reported per line.
     */
    public function parseCsv(Request $request): JsonResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']])['file'];
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        $get = fn (array $cells, string $col) => trim((string) ($cells[array_search($col, $header, true)] ?? ''));

        abort_if(array_diff(['type', 'question', 'correct', 'marks'], $header) !== [], 422, 'Use the template: type, question, option1..option6, correct, marks, tolerance, explanation.');

        $questions = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            $type = Str::of($get($cells, 'type'))->lower()->replace(['-', ' '], '_')->toString();
            $type = $type === 'mcq' ? QuizQuestion::SINGLE : $type;
            $options = array_values(array_filter(array_map(fn ($n) => in_array("option{$n}", $header, true) ? $get($cells, "option{$n}") : '', range(1, 6)), fn ($o) => $o !== ''));
            $correct = $get($cells, 'correct');

            $q = [
                'type' => $type,
                'question' => $get($cells, 'question'),
                'options' => $options,
                'marks' => $get($cells, 'marks') !== '' ? (float) $get($cells, 'marks') : 1,
                'explanation' => in_array('explanation', $header, true) ? ($get($cells, 'explanation') ?: null) : null,
            ];

            match ($type) {
                QuizQuestion::SINGLE => $q['correct_option'] = is_numeric($correct) ? (int) $correct - 1 : null,
                QuizQuestion::MULTIPLE => $q['correct_options'] = array_map(fn ($n) => (int) $n - 1, array_filter(preg_split('/[;|,]/', $correct), 'is_numeric')),
                QuizQuestion::TRUE_FALSE => $q['correct_option'] = in_array(strtolower($correct), ['true', 't', '1', 'yes'], true) ? 0 : 1,
                QuizQuestion::NUMERIC => [$q['correct_numeric'] = is_numeric($correct) ? (float) $correct : null, $q['tolerance'] = (float) ($get($cells, 'tolerance') ?: 0)],
                default => null,
            };

            try {
                $questions[] = QuizQuestionRules::validate([$q])[0];
            } catch (ValidationException $e) {
                $errors[] = "Line {$line}: ".implode(' ', array_map(fn ($m) => is_array($m) ? implode(' ', $m) : $m, $e->errors()));
            }
        }
        fclose($handle);

        if ($errors) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return response()->json(['questions' => $questions]);
    }
}

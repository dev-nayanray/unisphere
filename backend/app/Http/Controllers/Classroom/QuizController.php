<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuizController extends Controller
{
    public function index(Request $request, CourseSection $section): JsonResponse
    {
        $user = $request->user();
        $manage = Gate::allows('manage', $section);

        return response()->json(
            $section->quizzes()
                ->withCount('questions')
                ->withSum('questions as max_marks', 'marks')
                ->when(! $manage, fn ($q) => $q->whereNotNull('published_at')
                    ->with(['attempts' => fn ($q) => $q->where('student_id', $user->id)]))
                ->when($manage, fn ($q) => $q->withCount('attempts'))
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string'],
            'category' => ['sometimes', Rule::in(['quiz', 'mid', 'final'])],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:300'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'publish' => ['sometimes', 'boolean'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.type' => ['required', 'in:mcq,short'],
            'questions.*.question' => ['required', 'string'],
            'questions.*.options' => ['required_if:questions.*.type,mcq', 'nullable', 'array', 'min:2'],
            'questions.*.correct_option' => ['required_if:questions.*.type,mcq', 'nullable', 'integer', 'min:0'],
            'questions.*.marks' => ['required', 'numeric', 'min:0.5'],
        ]);

        $quiz = DB::transaction(function () use ($data, $section, $request) {
            $quiz = $section->quizzes()->create([
                ...collect($data)->except(['questions', 'publish'])->all(),
                'author_id' => $request->user()->id,
                'published_at' => ($data['publish'] ?? false) ? now() : null,
            ]);

            foreach ($data['questions'] as $i => $q) {
                $quiz->questions()->create([...$q, 'position' => $i]);
            }

            return $quiz;
        });

        return response()->json($quiz->load('questions'), 201);
    }

    /** Teachers see the answer key; students only see questions while the quiz is open. */
    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('view', $quiz);

        if (Gate::allows('update', $quiz)) {
            $quiz->load(['questions' => fn ($q) => $q->orderBy('position')]);
            $quiz->questions->each->makeVisible('correct_option');
            $quiz->load('attempts.student:id,name');

            return response()->json($quiz);
        }

        $questions = $quiz->questions()->get();

        return response()->json([
            ...$quiz->toArray(),
            'questions' => $quiz->shuffle_questions ? $questions->shuffle()->values() : $questions,
            'attempt' => $quiz->attempts()->where('student_id', $request->user()->id)->first(),
        ]);
    }

    public function start(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);
        abort_unless($quiz->isOpen(), 422, 'This quiz is not open right now.');

        $attempt = QuizAttempt::firstOrCreate(
            ['quiz_id' => $quiz->id, 'student_id' => $request->user()->id],
            ['started_at' => now()]
        );

        return response()->json($attempt);
    }

    /** Submit answers. MCQs are graded instantly; short answers wait for the teacher. */
    public function submit(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);

        $attempt = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_id', $request->user()->id)
            ->firstOrFail();

        abort_if($attempt->submitted_at !== null, 422, 'You have already submitted this quiz.');

        $deadline = $attempt->started_at->copy()->addMinutes($quiz->duration_minutes + 1); // 1 min grace
        abort_if(now()->greaterThan($deadline), 422, 'Time is up.');

        $answers = $request->validate(['answers' => ['required', 'array']])['answers'];

        $questions = $quiz->questions()->get()->each->makeVisible('correct_option');
        $score = 0.0;
        $needsManual = false;

        foreach ($questions as $question) {
            if ($question->type === 'mcq') {
                if (isset($answers[$question->id]) && (int) $answers[$question->id] === $question->correct_option) {
                    $score += (float) $question->marks;
                }
            } else {
                $needsManual = true;
            }
        }

        $attempt->update([
            'answers' => $answers,
            'score' => $needsManual ? null : $score,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => $needsManual ? 'Submitted. Short answers will be graded by your teacher.' : 'Submitted.',
            'score' => $needsManual ? null : $score,
            'max_marks' => (float) $questions->sum('marks'),
        ]);
    }

    /** Teacher sets the final score after reviewing short answers. */
    public function gradeAttempt(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $quiz = $attempt->quiz;
        Gate::authorize('grade', $attempt);

        $attempt->update($request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:'.$quiz->maxMarks()],
        ]));

        return response()->json($attempt);
    }
}

<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Post;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\QuizGrader;
use App\Services\QuizQuestionRules;
use App\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    public function __construct(private readonly QuizGrader $grader)
    {
    }

    public function index(Request $request, CourseSection $section): JsonResponse
    {
        $user = $request->user();
        $manage = Gate::allows('manage', $section);

        $quizzes = $section->quizzes()
            ->withCount('questions')
            ->withSum('questions as max_marks', 'marks')
            ->when(! $manage, fn ($q) => $q->whereNotNull('published_at')
                ->with(['attempts' => fn ($q) => $q->where('student_id', $user->id)]))
            ->when($manage, fn ($q) => $q->withCount(['attempts as submitted_count' => fn ($q) => $q->whereNotNull('submitted_at')]))
            ->latest()
            ->get();

        if (! $manage) {
            // Scores stay hidden until the quiz's "show results" setting allows them.
            $quizzes->each(function (Quiz $quiz) {
                if (! $quiz->resultsVisible()) {
                    $quiz->attempts->each(fn ($a) => $a->setAttribute('score', null));
                }
            });
        }

        return response()->json($quizzes);
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);
        $data = $this->validated($request, $section);

        $quiz = DB::transaction(function () use ($data, $section, $request) {
            $quiz = $section->quizzes()->create([
                ...$this->settings($data),
                'author_id' => $request->user()->id,
                'published_at' => ($data['publish'] ?? false) ? now() : null,
            ]);
            $this->replaceQuestions($quiz, $data['questions']);

            return $quiz;
        });

        if ($quiz->published_at) {
            Post::announceClasswork($section, $request->user(), 'quiz', $quiz->id, $quiz->title);
        }

        return response()->json($this->teacherView($quiz), 201);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('update', $quiz);
        $data = $this->validated($request, $quiz->section, $quiz);

        if (isset($data['questions']) && $quiz->attempts()->whereNotNull('submitted_at')->exists()) {
            throw ValidationException::withMessages([
                'questions' => 'Students have already submitted this quiz, so its questions can no longer change. Duplicate the quiz instead.',
            ]);
        }

        $wasDraft = $quiz->published_at === null;

        DB::transaction(function () use ($quiz, $data) {
            $settings = $this->settings($data);
            if (array_key_exists('publish', $data)) {
                $settings['published_at'] = $data['publish'] ? ($quiz->published_at ?? now()) : null;
            }
            $quiz->update($settings);

            if (isset($data['questions'])) {
                $this->replaceQuestions($quiz, $data['questions']);
            }
        });

        if ($wasDraft && $quiz->published_at) {
            Post::announceClasswork($quiz->section, $request->user(), 'quiz', $quiz->id, $quiz->title);
        }

        return response()->json($this->teacherView($quiz->fresh()));
    }

    public function destroy(Quiz $quiz): JsonResponse
    {
        Gate::authorize('update', $quiz);
        $quiz->delete();

        return response()->json(['message' => 'Quiz deleted.']);
    }

    /** Teachers get the answer key; students get the quiz's details while it is open. */
    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('view', $quiz);

        if (Gate::allows('update', $quiz)) {
            return response()->json($this->teacherView($quiz));
        }

        return response()->json([
            ...$quiz->only(['id', 'title', 'instructions', 'category', 'duration_minutes', 'starts_at', 'ends_at', 'show_results', 'course_section_id']),
            'question_count' => $quiz->draw_count ?: $quiz->questions()->count(),
            'attempt' => $quiz->attempts()->where('student_id', $request->user()->id)->first(['id', 'started_at', 'submitted_at']),
        ]);
    }

    /** What a student will see, without answers, for the teacher to check. */
    public function preview(Quiz $quiz): JsonResponse
    {
        Gate::authorize('update', $quiz);

        $questions = $quiz->questions()->get();
        if ($quiz->shuffle_questions) {
            $questions = $questions->shuffle();
        }
        if ($quiz->draw_count) {
            $questions = $questions->take($quiz->draw_count);
        }

        return response()->json([
            ...$quiz->only(['id', 'title', 'instructions', 'duration_minutes']),
            'questions' => $questions->map(fn ($q) => $q->forStudent($quiz->shuffle_options))->values(),
        ]);
    }

    /** "Manual" results: let students see scores and answers now. */
    public function releaseResults(Quiz $quiz): JsonResponse
    {
        Gate::authorize('update', $quiz);
        $quiz->update(['results_released_at' => now()]);

        return response()->json(['message' => 'Results released to students.']);
    }

    /** Start (or resume) an attempt. The student's questions are drawn and ordered once. */
    public function start(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);
        abort_unless($quiz->isOpen(), 422, 'This quiz is not open right now.');

        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $request->user()->id)->first();

        if (! $attempt) {
            $questions = $quiz->questions()->get();
            if ($quiz->draw_count && $quiz->draw_count < $questions->count()) {
                $questions = $questions->random($quiz->draw_count);
            }
            if ($quiz->shuffle_questions) {
                $questions = $questions->shuffle();
            }

            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $request->user()->id,
                'started_at' => now(),
                'question_ids' => $questions->pluck('id')->values()->all(),
                'max_score' => $questions->sum(fn ($q) => (float) $q->marks),
            ]);
        }

        return response()->json([
            'attempt' => $attempt->only(['id', 'started_at', 'submitted_at', 'answers', 'max_score']),
            'questions' => $attempt->questions()->map(fn ($q) => $q->forStudent($quiz->shuffle_options))->values(),
        ]);
    }

    /** Submit answers. Everything except written (short) answers is graded instantly. */
    public function submit(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);

        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $request->user()->id)->firstOrFail();
        abort_if($attempt->submitted_at !== null, 422, 'You have already submitted this quiz.');

        $deadline = $attempt->started_at->copy()->addMinutes($quiz->duration_minutes + 1); // 1 min grace
        abort_if(now()->greaterThan($deadline), 422, 'Time is up.');

        $answers = $request->validate(['answers' => ['required', 'array']])['answers'];
        $questions = $attempt->questions()->each(fn ($q) => $q->withAnswers());
        $graded = $this->grader->gradeAll($questions, $answers);

        $attempt->update([
            'answers' => $answers,
            'results' => $graded['results'],
            'score' => $graded['score'],
            'submitted_at' => now(),
        ]);

        $visible = $quiz->resultsVisible();

        return response()->json([
            'message' => $graded['score'] === null
                ? 'Submitted. Written answers will be graded by your teacher.'
                : ($visible ? 'Submitted.' : 'Submitted. Your teacher will release the results later.'),
            'score' => $visible ? $graded['score'] : null,
            'max_marks' => (float) $attempt->max_score,
        ]);
    }

    /** Teacher sets the final score after reviewing written answers. */
    public function gradeAttempt(Request $request, QuizAttempt $attempt): JsonResponse
    {
        Gate::authorize('grade', $attempt);

        $attempt->update($request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:'.($attempt->max_score ?? $attempt->quiz->maxMarks())],
        ]));

        return response()->json($attempt);
    }

    private function validated(Request $request, CourseSection $section, ?Quiz $quiz = null): array
    {
        $req = $quiz ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'category' => ['sometimes', Rule::in(['quiz', 'mid', 'final'])],
            'topic_id' => ['nullable', Rule::exists('topics', 'id')->where('course_section_id', $section->id)],
            'duration_minutes' => [$req, 'integer', 'min:1', 'max:300'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'shuffle_options' => ['sometimes', 'boolean'],
            'show_results' => ['sometimes', Rule::in([Quiz::RESULTS_IMMEDIATELY, Quiz::RESULTS_AFTER_CLOSE, Quiz::RESULTS_MANUAL])],
            'draw_count' => ['nullable', 'integer', 'min:1'],
            'publish' => ['sometimes', 'boolean'],
            'questions' => [$req, 'array', 'min:1', 'max:200'],
            ...QuizQuestionRules::rules(),
        ]);

        if (isset($data['questions'])) {
            $data['questions'] = QuizQuestionRules::check($data['questions']);
        }

        $count = isset($data['questions']) ? count($data['questions']) : $quiz?->questions()->count();
        if (! empty($data['draw_count']) && $data['draw_count'] > $count) {
            throw ValidationException::withMessages(['draw_count' => "The quiz has only {$count} questions to draw from."]);
        }

        return $data;
    }

    private function settings(array $data): array
    {
        $settings = collect($data)->only([
            'title', 'category', 'topic_id', 'duration_minutes', 'starts_at', 'ends_at',
            'shuffle_questions', 'shuffle_options', 'show_results', 'draw_count',
        ])->all();

        if (array_key_exists('instructions', $data)) {
            $settings['instructions'] = SafeText::clean($data['instructions']);
        }

        return $settings;
    }

    private function replaceQuestions(Quiz $quiz, array $questions): void
    {
        $quiz->questions()->delete();

        foreach (array_values($questions) as $i => $q) {
            $quiz->questions()->create([
                ...collect($q)->only(['type', 'options', 'correct_option', 'correct_options', 'correct_numeric', 'tolerance', 'marks'])->all(),
                'question' => SafeText::clean($q['question']),
                'explanation' => isset($q['explanation']) ? SafeText::clean($q['explanation']) : null,
                'position' => $i,
            ]);
        }
    }

    private function teacherView(Quiz $quiz): array
    {
        $quiz->load(['questions', 'topic:id,name', 'section.course:id,code,title']);
        $quiz->questions->each(fn ($q) => $q->withAnswers());

        return [
            ...$quiz->toArray(),
            'max_marks' => (float) $quiz->questions->sum('marks'),
            'submitted_count' => $quiz->attempts()->whereNotNull('submitted_at')->count(),
        ];
    }
}

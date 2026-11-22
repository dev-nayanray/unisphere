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
            ...$quiz->only(['id', 'title', 'instructions', 'category', 'duration_minutes', 'starts_at', 'ends_at', 'show_results', 'display_mode', 'course_section_id']),
            'is_open' => $quiz->isOpen(),
            'results_visible' => $quiz->resultsVisible(),
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

        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $request->user()->id)->first();

        if ($attempt?->isSubmitted()) {
            return response()->json(['message' => 'You have already submitted this quiz.', 'attempt' => $attempt->only(['id', 'submitted_at'])], 422);
        }

        // Time ran out while the student was away: submit what was saved.
        if ($attempt && $attempt->isExpired()) {
            $attempt->finalize($this->grader, auto: true);

            return response()->json(['message' => 'Time is up. Your saved answers were submitted.', 'attempt' => $attempt->only(['id', 'submitted_at'])], 422);
        }

        if (! $attempt) {
            abort_unless($quiz->isOpen(), 422, 'This quiz is not open right now.');

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
            'attempt' => [
                ...$attempt->only(['id', 'started_at', 'answers', 'max_score']),
                'deadline' => $attempt->deadline()->toIso8601String(),
                'seconds_left' => $attempt->secondsLeft(),
            ],
            'display_mode' => $quiz->display_mode,
            // Options are shuffled the same way on every resume for this attempt.
            'questions' => $this->questionsFor($attempt, $quiz),
        ]);
    }

    /** Autosave (every few seconds and on change). Refused once time is up. */
    public function saveAnswers(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);
        $attempt = $this->ownAttempt($request, $quiz);

        abort_if($attempt->isSubmitted(), 422, 'This quiz has already been submitted.');
        abort_if($attempt->isExpired(QuizAttempt::GRACE_SECONDS), 422, 'Time is up.');

        $answers = $request->validate(['answers' => ['present', 'array']])['answers'];
        // Only answers to this attempt's own questions are kept.
        $answers = array_intersect_key($answers, array_flip($attempt->question_ids ?? array_keys($answers)));

        $attempt->update(['answers' => $answers, 'last_saved_at' => now()]);

        return response()->json(['saved_at' => $attempt->last_saved_at, 'seconds_left' => $attempt->secondsLeft()]);
    }

    /** Record a tab switch or copy/paste. The teacher sees the counts. */
    public function logEvent(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);
        $attempt = $this->ownAttempt($request, $quiz);

        if (! $attempt->isSubmitted()) {
            $type = $request->validate(['type' => ['required', Rule::in(QuizAttempt::INTEGRITY_EVENTS)]])['type'];
            $integrity = $attempt->integrity ?? [];
            $integrity[$type] = ($integrity[$type] ?? 0) + 1;
            $integrity['events'] = array_slice([...($integrity['events'] ?? []), ['type' => $type, 'at' => now()->toIso8601String()]], -100);
            $attempt->update(['integrity' => $integrity]);
        }

        return response()->json(['ok' => true]);
    }

    /** Submit answers. Everything except written (short) answers is graded instantly. */
    public function submit(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('take', $quiz);
        $attempt = $this->ownAttempt($request, $quiz);
        abort_if($attempt->isSubmitted(), 422, 'You have already submitted this quiz.');

        $answers = $request->validate(['answers' => ['present', 'array']])['answers'];

        // The deadline comes from the server. After it (plus a short grace for a
        // submit already in flight), only the last autosave counts.
        if ($attempt->isExpired(QuizAttempt::GRACE_SECONDS)) {
            $attempt->finalize($this->grader, auto: true);
            $message = 'Time was up, so your last saved answers were submitted.';
        } else {
            $attempt->finalize($this->grader, array_intersect_key($answers, array_flip($attempt->question_ids ?? array_keys($answers))));
            $message = null;
        }

        $visible = $quiz->resultsVisible();
        $attempt->refresh();

        return response()->json([
            'message' => $message ?? ($attempt->score === null
                ? 'Submitted. Written answers will be graded by your teacher.'
                : ($visible ? 'Submitted.' : 'Submitted. Your teacher will release the results later.')),
            'score' => $visible ? $attempt->score : null,
            'max_marks' => (float) $attempt->max_score,
            'results_visible' => $visible,
        ]);
    }

    /** The student's results: score, their answers, the correct answers and explanations (when allowed). */
    public function result(Request $request, Quiz $quiz): JsonResponse
    {
        Gate::authorize('view', $quiz);
        $attempt = $this->ownAttempt($request, $quiz);
        abort_unless($attempt->isSubmitted(), 422, 'Submit the quiz first.');

        if (! $quiz->resultsVisible()) {
            return response()->json(['results_visible' => false, 'submitted_at' => $attempt->submitted_at, 'show_results' => $quiz->show_results]);
        }

        return response()->json([
            'results_visible' => true,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'submitted_at' => $attempt->submitted_at,
            'questions' => $this->review($attempt),
        ]);
    }

    /** Teacher: every attempt plus per-question statistics. */
    public function attempts(Quiz $quiz): JsonResponse
    {
        Gate::authorize('update', $quiz);

        $attempts = $quiz->attempts()->with('student:id,name')->orderBy('submitted_at')->get();
        $questions = $quiz->questions()->get()->each(fn ($q) => $q->withAnswers());

        $stats = $questions->map(function ($q) use ($attempts) {
            $answered = $attempts->filter(fn ($a) => $a->isSubmitted() && in_array($q->id, $a->question_ids ?? [$q->id], true));
            $results = $answered->map(fn ($a) => $a->results[$q->id] ?? null)->filter();
            $correct = $results->where('correct', true)->count();

            return [
                'question_id' => $q->id,
                'question' => $q->question,
                'type' => $q->type,
                'attempts' => $answered->count(),
                'percent_correct' => $q->needsManualGrading() || $answered->isEmpty() ? null : round($correct / $answered->count() * 100, 1),
                'average_score' => $results->whereNotNull('score')->avg('score'),
                'pending' => $results->whereNull('score')->count(),
            ];
        });

        return response()->json([
            'attempts' => $attempts->map(fn ($a) => [
                'id' => $a->id,
                'student' => $a->student,
                'started_at' => $a->started_at,
                'submitted_at' => $a->submitted_at,
                'auto_submitted' => $a->auto_submitted,
                'score' => $a->score,
                'max_score' => $a->max_score,
                'needs_grading' => $a->isSubmitted() && $a->score === null,
                'integrity' => $a->integritySummary(),
            ]),
            'stats' => $stats,
        ]);
    }

    /** Teacher: one attempt question by question, to mark written answers. */
    public function showAttempt(QuizAttempt $attempt): JsonResponse
    {
        Gate::authorize('grade', $attempt);

        return response()->json([
            ...$attempt->load('student:id,name')->only(['id', 'student', 'score', 'max_score', 'started_at', 'submitted_at', 'auto_submitted']),
            'integrity' => $attempt->integrity ?? [],
            'questions' => $this->review($attempt),
        ]);
    }

    /** Teacher marks written answers (or overrides any question): {scores: {question_id: points}}. */
    public function gradeAnswers(Request $request, QuizAttempt $attempt): JsonResponse
    {
        Gate::authorize('grade', $attempt);
        abort_unless($attempt->isSubmitted(), 422, 'This attempt has not been submitted yet.');

        $questions = $attempt->questions()->keyBy('id');
        $scores = $request->validate(['scores' => ['required', 'array']])['scores'];

        $results = $attempt->results ?? [];
        foreach ($scores as $questionId => $points) {
            $question = $questions->get((int) $questionId);
            if (! $question) {
                throw ValidationException::withMessages(["scores.{$questionId}" => 'That question is not in this attempt.']);
            }
            if (! is_numeric($points) || $points < 0 || $points > (float) $question->marks) {
                throw ValidationException::withMessages(["scores.{$questionId}" => 'Give between 0 and '.(float) $question->marks.' points.']);
            }
            $results[$questionId] = ['score' => (float) $points, 'correct' => (float) $points >= (float) $question->marks, 'manual' => true];
        }

        $attempt->update(['results' => $results]);
        $attempt->recalculateScore();

        return response()->json($attempt->fresh()->only(['id', 'score', 'max_score', 'results']));
    }

    /** Teacher lets a student take the quiz again (deletes the attempt). */
    public function resetAttempt(QuizAttempt $attempt): JsonResponse
    {
        Gate::authorize('grade', $attempt);
        $attempt->delete();

        return response()->json(['message' => 'Attempt removed. The student can take the quiz again.']);
    }

    private function ownAttempt(Request $request, Quiz $quiz): QuizAttempt
    {
        return QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $request->user()->id)->firstOrFail();
    }

    /** Questions for the student, with options shuffled consistently for this attempt. */
    private function questionsFor(QuizAttempt $attempt, Quiz $quiz): array
    {
        return $attempt->questions()->map(function ($q) use ($attempt, $quiz) {
            $data = $q->forStudent(false);
            if ($quiz->shuffle_options && $q->type !== 'true_false') {
                // Seeded by attempt + question, so a refresh shows the same order.
                mt_srand(crc32("{$attempt->id}-{$q->id}"));
                $options = $data['options'];
                for ($i = count($options) - 1; $i > 0; $i--) {
                    $j = mt_rand(0, $i);
                    [$options[$i], $options[$j]] = [$options[$j], $options[$i]];
                }
                $data['options'] = $options;
                mt_srand();
            }

            return $data;
        })->values()->all();
    }

    /** Each question with the student's answer, the correct answer, points and explanation. */
    private function review(QuizAttempt $attempt): array
    {
        return $attempt->questions()->map(function ($q) use ($attempt) {
            $q->withAnswers();

            return [
                ...$q->forStudent(false),
                'answer' => $attempt->answers[$q->id] ?? null,
                'result' => $attempt->results[$q->id] ?? null,
                'correct_option' => $q->correct_option,
                'correct_options' => $q->correct_options,
                'correct_numeric' => $q->correct_numeric,
                'tolerance' => $q->tolerance,
                'explanation' => $q->explanation,
            ];
        })->values()->all();
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
            'display_mode' => ['sometimes', Rule::in(['all', 'one'])],
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
            'shuffle_questions', 'shuffle_options', 'show_results', 'draw_count', 'display_mode',
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

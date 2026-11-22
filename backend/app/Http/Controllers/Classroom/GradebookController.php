<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseSection;
use App\Models\GradebookExcusal;
use App\Models\GradebookOverride;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\Submission;
use App\Services\ResultSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradebookController extends Controller
{
    public function __construct(private readonly ResultSyncService $results)
    {
    }

    /** Teacher: whole class. Student: only their own row. */
    public function show(Request $request, CourseSection $section): JsonResponse
    {
        $staff = Gate::allows('viewResults', $section);

        // Students see only their own row, counting only grades that were returned to them.
        $rows = $this->results->gradebook($section, onlyReturned: ! $staff);

        if (! $staff) {
            $rows = $rows->filter(fn ($row) => $row['student']->id === $request->user()->id)->values();
        }

        $columns = $this->results->columns($section)->map(fn ($c) => collect($c)->except('model', 'only')->all());

        return response()->json([
            'scheme' => $section->gradingScheme(),
            'missing_as_zero' => $section->missing_as_zero,
            'columns' => $columns,
            'rows' => $rows,
        ]);
    }

    /**
     * Teacher edits one cell: marks for an assignment (creating the
     * submission if the work was handed in on paper) or a quiz score
     * override, and/or excusing the student from that item.
     */
    public function updateCell(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'item' => ['required', 'string', 'regex:/^(assignment|quiz):\d+$/'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'excused' => ['sometimes', 'boolean'],
            'return' => ['sometimes', 'boolean'],
        ]);

        abort_unless($section->registrations()->where('student_id', $data['student_id'])->where('status', Registration::APPROVED)->exists(), 422, 'That student is not enrolled in this class.');
        [$type, $id] = explode(':', $data['item']);
        $item = $type === 'assignment'
            ? Assignment::where('course_section_id', $section->id)->findOrFail($id)
            : Quiz::where('course_section_id', $section->id)->with('questions:id,quiz_id,marks')->findOrFail($id);

        if (array_key_exists('excused', $data)) {
            $key = ['student_id' => $data['student_id'], 'item_type' => $type, 'item_id' => $item->id];
            $data['excused']
                ? GradebookExcusal::firstOrCreate($key, ['course_section_id' => $section->id, 'excused_by' => $request->user()->id])
                : GradebookExcusal::where($key)->delete();
        }

        if (array_key_exists('value', $data)) {
            $type === 'assignment'
                ? $this->setAssignmentMarks($item, $data, $request)
                : $this->setQuizScore($item, $data);
        }

        $row = $this->results->gradebook($section)->firstWhere('student.id', (int) $data['student_id']);

        return response()->json($row);
    }

    /** Teacher's final say on a student's total. Send total = null to remove. */
    public function override(Request $request, CourseSection $section, int $student): JsonResponse
    {
        $this->ensureCanManage($section);
        abort_unless($section->registrations()->where('student_id', $student)->where('status', Registration::APPROVED)->exists(), 404);

        $data = $request->validate([
            'total' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['required_with:total', 'nullable', 'string', 'max:500'],
        ]);

        if ($data['total'] === null) {
            GradebookOverride::where('course_section_id', $section->id)->where('student_id', $student)->delete();
        } else {
            GradebookOverride::updateOrCreate(
                ['course_section_id' => $section->id, 'student_id' => $student],
                ['total' => $data['total'], 'note' => $data['note'], 'set_by' => $request->user()->id]
            );
        }

        return response()->json($this->results->gradebook($section)->firstWhere('student.id', $student));
    }

    public function updateSettings(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);
        $section->update($request->validate(['missing_as_zero' => ['required', 'boolean']]));

        return response()->json(['missing_as_zero' => $section->missing_as_zero]);
    }

    /** Full gradebook as CSV: every item, category marks, total and grade. */
    public function export(CourseSection $section): StreamedResponse
    {
        Gate::authorize('viewResults', $section);
        $section->loadMissing('course:id,code');

        $columns = $this->results->columns($section);
        $rows = $this->results->gradebook($section, columns: $columns);
        $scheme = $section->gradingScheme();
        $name = str($section->course->code.'-'.$section->section.'-gradebook')->slug().'.csv';

        return response()->streamDownload(function () use ($columns, $rows, $scheme) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Student', 'Email',
                ...$columns->map(fn ($c) => "{$c['title']} (/{$c['max']})"),
                ...collect($scheme)->map(fn ($w, $cat) => ucfirst($cat)." ({$w})")->values(),
                'Calculated total', 'Final total', 'Grade', 'Grade point', 'Override note',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['student']->name, $r['student']->email,
                    ...$columns->map(function ($c) use ($r) {
                        $cell = $r['cells'][$c['key']];

                        return match (true) {
                            $cell['status'] === 'excused' => 'EX',
                            $cell['status'] === 'not_assigned' => '',
                            $cell['value'] !== null => $cell['value'],
                            ! empty($cell['counted_as_zero']) => '0 (missing)',
                            default => '',
                        };
                    }),
                    ...array_values($r['breakdown']),
                    $r['computed_total'], $r['total'], $r['letter_grade'], $r['grade_point'], $r['override']['note'] ?? '',
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    private function setAssignmentMarks(Assignment $assignment, array $data, Request $request): void
    {
        if ($data['value'] !== null && $data['value'] > (float) $assignment->max_marks) {
            throw ValidationException::withMessages(['value' => 'Marks cannot be more than '.(float) $assignment->max_marks.'.']);
        }

        $submission = Submission::firstOrNew(['assignment_id' => $assignment->id, 'student_id' => $data['student_id']]);
        $submission->fill([
            'marks' => $data['value'],
            'graded_at' => $data['value'] === null ? null : now(),
            'graded_by' => $data['value'] === null ? null : $request->user()->id,
        ]);
        if ($data['return'] ?? false) {
            $submission->returned_at = $data['value'] === null ? null : now();
        }
        $submission->save();
    }

    private function setQuizScore(Quiz $quiz, array $data): void
    {
        $attempt = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $data['student_id'])->first();

        if ($data['value'] === null) {
            abort_unless($attempt, 422, 'There is no attempt to clear.');
            $attempt->update(['score' => null]);

            return;
        }

        $max = (float) ($attempt?->max_score ?: $quiz->questions->sum('marks'));
        if ($data['value'] > $max) {
            throw ValidationException::withMessages(['value' => "The score cannot be more than {$max}."]);
        }

        // A quiz taken on paper gets an attempt record so the score is kept.
        QuizAttempt::updateOrCreate(
            ['quiz_id' => $quiz->id, 'student_id' => $data['student_id']],
            ['score' => $data['value'], 'max_score' => $max, 'started_at' => $attempt?->started_at ?? now(), 'submitted_at' => $attempt?->submitted_at ?? now()]
        );
    }

    /** Push the gradebook into the official results table (as drafts awaiting publication). */
    public function sync(CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);
        $count = $this->results->sync($section);

        return response()->json(['message' => "Results updated for {$count} students. Awaiting publication."]);
    }

    public function updateScheme(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $scheme = $request->validate([
            'scheme' => ['required', 'array'],
            'scheme.*' => ['numeric', 'min:0', 'max:100'],
        ])['scheme'];

        abort_unless(abs(array_sum($scheme) - 100) < 0.01, 422, 'Weights must add up to 100.');
        $section->update(['grading_scheme' => $scheme]);

        return response()->json(['scheme' => $scheme]);
    }

    /** Count approved students, used by the front end before syncing. */
    public function summary(CourseSection $section): JsonResponse
    {
        return response()->json([
            'students' => $section->registrations()->where('status', Registration::APPROVED)->count(),
            'assignments' => $section->assignments()->count(),
            'quizzes' => $section->quizzes()->count(),
            'attendance_sessions' => $section->attendanceSessions()->count(),
        ]);
    }
}

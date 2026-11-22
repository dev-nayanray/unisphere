<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\CourseSection;
use App\Models\GradebookExcusal;
use App\Models\GradebookOverride;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\Result;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * Turns classroom work (assignments, quizzes, attendance) into the official
 * course result, so teachers never type marks twice.
 *
 * Each student's row has one cell per published assignment and quiz. A
 * category's mark is the average percentage of its counted cells times the
 * category weight. A cell counts when it has a grade; an empty cell counts as
 * 0 only when the section treats missing work as zero and the work is past
 * due. Excused cells never count. A teacher override replaces the total.
 */
class ResultSyncService
{
    public function __construct(private readonly GradeService $grades)
    {
    }

    /** The gradebook's columns: published assignments and quizzes, oldest first. */
    public function columns(CourseSection $section): Collection
    {
        $assignments = $section->assignments()
            ->whereNotNull('published_at')
            ->with('assignedStudents:id')
            ->get(['id', 'course_section_id', 'title', 'category', 'max_marks', 'due_at', 'late_policy', 'late_penalty_percent', 'assign_to', 'published_at'])
            ->map(fn (Assignment $a) => [
                'key' => "assignment:{$a->id}",
                'type' => 'assignment',
                'id' => $a->id,
                'title' => $a->title,
                'category' => $a->category,
                'max' => (float) $a->max_marks,
                'due_at' => $a->due_at,
                'past_due' => $a->isPastDue(),
                'only' => $a->assign_to === 'all' ? null : $a->assignedStudents->pluck('id')->all(),
                'model' => $a,
            ]);

        $quizzes = $section->quizzes()
            ->whereNotNull('published_at')
            ->with('questions:id,quiz_id,marks')
            ->get()
            ->map(fn (Quiz $q) => [
                'key' => "quiz:{$q->id}",
                'type' => 'quiz',
                'id' => $q->id,
                'title' => $q->title,
                'category' => $q->category,
                'max' => (float) $q->questions->sum('marks'),
                'due_at' => $q->ends_at,
                'past_due' => $q->isClosed(),
                'only' => null,
                'model' => $q,
            ]);

        return $assignments->concat($quizzes)->sortBy(fn ($c) => $c['due_at']?->timestamp ?? PHP_INT_MAX)->values();
    }

    /**
     * Build the gradebook for a section: one row per enrolled student with
     * per-item cells and the weighted mark for each grading category.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function gradebook(CourseSection $section, bool $onlyReturned = false, ?Collection $columns = null): Collection
    {
        $scheme = $section->gradingScheme();
        $columns ??= $this->columns($section);

        $registrations = $section->registrations()
            ->where('status', Registration::APPROVED)
            ->with('student:id,name,email')
            ->get()
            ->sortBy(fn ($r) => $r->student?->name)
            ->values();
        $studentIds = $registrations->pluck('student_id');

        $submissions = Submission::query()
            ->whereIn('assignment_id', $columns->where('type', 'assignment')->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->get(['id', 'assignment_id', 'student_id', 'marks', 'submitted_at', 'returned_at', 'is_late'])
            ->keyBy(fn ($s) => "{$s->student_id}:{$s->assignment_id}");

        $attempts = QuizAttempt::query()
            ->whereIn('quiz_id', $columns->where('type', 'quiz')->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->get(['id', 'quiz_id', 'student_id', 'score', 'max_score', 'submitted_at'])
            ->keyBy(fn ($a) => "{$a->student_id}:{$a->quiz_id}");

        $excused = GradebookExcusal::where('course_section_id', $section->id)->get()
            ->keyBy(fn ($e) => "{$e->student_id}:{$e->item_type}:{$e->item_id}");
        $overrides = GradebookOverride::where('course_section_id', $section->id)->get()->keyBy('student_id');

        $sessionCount = $section->attendanceSessions()->count();
        $attendance = AttendanceRecord::query()
            ->whereIn('attendance_session_id', $section->attendanceSessions()->select('id'))
            ->whereIn('status', ['present', 'late'])
            ->selectRaw('student_id, count(*) as attended')
            ->groupBy('student_id')
            ->pluck('attended', 'student_id');

        return $registrations->map(function (Registration $registration) use (
            $section, $scheme, $columns, $submissions, $attempts, $excused, $overrides, $attendance, $sessionCount, $onlyReturned
        ) {
            $studentId = $registration->student_id;
            $percentages = [];
            $cells = [];

            foreach ($columns as $col) {
                if ($col['only'] !== null && ! in_array($studentId, $col['only'], true)) {
                    $cells[$col['key']] = ['status' => 'not_assigned', 'value' => null];
                    continue;
                }

                $cell = $col['type'] === 'assignment'
                    ? $this->assignmentCell($col, $submissions->get("{$studentId}:{$col['id']}"), $onlyReturned)
                    : $this->quizCell($col, $attempts->get("{$studentId}:{$col['id']}"));

                if ($excused->has("{$studentId}:{$col['type']}:{$col['id']}")) {
                    $cell['status'] = 'excused';
                    $cells[$col['key']] = $cell;
                    continue;
                }

                if ($cell['value'] === null && $section->missing_as_zero && $col['past_due'] && $cell['status'] === 'missing') {
                    $cell['counted_as_zero'] = true;
                    $percentages[$col['category']][] = 0.0;
                } elseif ($cell['value'] !== null && $cell['max'] > 0) {
                    $percentages[$col['category']][] = $cell['value'] / $cell['max'] * 100;
                }

                $cells[$col['key']] = $cell;
            }

            if ($sessionCount > 0) {
                $percentages['attendance'][] = ($attendance[$studentId] ?? 0) / $sessionCount * 100;
            }

            $breakdown = [];
            $total = 0.0;
            foreach ($scheme as $category => $weight) {
                $avg = isset($percentages[$category])
                    ? array_sum($percentages[$category]) / count($percentages[$category])
                    : 0.0;
                $breakdown[$category] = round($avg / 100 * $weight, 2);
                $total += $breakdown[$category];
            }

            $computed = round($total, 2);
            $override = $overrides->get($studentId);
            $final = $override ? (float) $override->total : $computed;
            [$letter, $point] = $this->grades->grade($final);

            return [
                'registration_id' => $registration->id,
                'student' => $registration->student,
                'cells' => $cells,
                'breakdown' => $breakdown,
                'computed_total' => $computed,
                'override' => $override ? ['total' => (float) $override->total, 'note' => $override->note, 'updated_at' => $override->updated_at] : null,
                'total' => $final,
                'letter_grade' => $letter,
                'grade_point' => $point,
            ];
        })->values();
    }

    private function assignmentCell(array $col, ?Submission $s, bool $onlyReturned): array
    {
        $graded = $s && $s->marks !== null && (! $onlyReturned || $s->returned_at !== null);
        // Late penalties are applied here, so the official result matches the policy.
        $value = $graded ? $col['model']->effectiveMarks($s) : null;

        return [
            'status' => $graded ? ($s->returned_at ? 'returned' : 'graded') : ($s?->submitted_at ? 'submitted' : 'missing'),
            'value' => $value,
            'raw' => $graded ? (float) $s->marks : null,
            'max' => $col['max'],
            'late' => (bool) $s?->is_late,
            'submission_id' => $s?->id,
        ];
    }

    private function quizCell(array $col, ?QuizAttempt $a): array
    {
        $done = $a && $a->submitted_at !== null;

        return [
            'status' => ! $a ? 'missing' : (! $done ? 'in_progress' : ($a->score === null ? 'submitted' : 'graded')),
            'value' => $done && $a->score !== null ? (float) $a->score : null,
            // With "draw N questions", each attempt has its own maximum.
            'max' => (float) ($a?->max_score ?: $col['max']),
            'attempt_id' => $a?->id,
        ];
    }

    /** Save the gradebook into the results table (as unpublished drafts). */
    public function sync(CourseSection $section): int
    {
        $rows = $this->gradebook($section);

        foreach ($rows as $row) {
            Result::updateOrCreate(
                ['registration_id' => $row['registration_id']],
                [
                    'breakdown' => $row['breakdown'],
                    'total' => $row['total'],
                    'letter_grade' => $row['letter_grade'],
                    'grade_point' => $row['grade_point'],
                ]
            );
        }

        return $rows->count();
    }
}

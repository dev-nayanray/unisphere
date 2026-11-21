<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\CourseSection;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\Result;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * Turns classroom work (assignments, quizzes, attendance) into the official
 * course result, so teachers never type marks twice.
 */
class ResultSyncService
{
    public function __construct(private readonly GradeService $grades)
    {
    }

    /**
     * Build the gradebook for a section: one row per enrolled student with the
     * weighted mark for each grading category.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function gradebook(CourseSection $section): Collection
    {
        $scheme = $section->gradingScheme();

        $section->load([
            'registrations' => fn ($q) => $q->where('status', Registration::APPROVED)->with('student:id,name,email'),
            'assignments:id,course_section_id,title,category,max_marks,due_at,late_policy,late_penalty_percent',
            'quizzes.questions:id,quiz_id,marks',
        ]);

        $studentIds = $section->registrations->pluck('student_id');

        $submissions = Submission::query()
            ->whereIn('assignment_id', $section->assignments->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('marks')
            ->get()
            ->groupBy('student_id');

        $attempts = QuizAttempt::query()
            ->whereIn('quiz_id', $section->quizzes->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('score')
            ->get()
            ->groupBy('student_id');

        $sessionCount = $section->attendanceSessions()->count();
        $attendance = AttendanceRecord::query()
            ->whereIn('attendance_session_id', $section->attendanceSessions()->select('id'))
            ->whereIn('status', ['present', 'late'])
            ->selectRaw('student_id, count(*) as attended')
            ->groupBy('student_id')
            ->pluck('attended', 'student_id');

        $assignmentMax = $section->assignments->keyBy('id');
        $quizMax = $section->quizzes->mapWithKeys(fn ($q) => [$q->id => (float) $q->questions->sum('marks')]);
        $quizCategory = $section->quizzes->pluck('category', 'id');

        return $section->registrations->map(function (Registration $registration) use (
            $scheme, $submissions, $attempts, $attendance, $sessionCount, $assignmentMax, $quizMax, $quizCategory
        ) {
            // Collect percentage scores per category.
            $percentages = [];

            foreach ($submissions->get($registration->student_id, collect()) as $s) {
                $a = $assignmentMax[$s->assignment_id];
                if ((float) $a->max_marks > 0) {
                    // Late penalties are applied here, so the official result matches the policy.
                    $percentages[$a->category][] = $a->effectiveMarks($s) / (float) $a->max_marks * 100;
                }
            }

            foreach ($attempts->get($registration->student_id, collect()) as $attempt) {
                $max = $quizMax[$attempt->quiz_id] ?? 0;
                if ($max > 0) {
                    $percentages[$quizCategory[$attempt->quiz_id]][] = (float) $attempt->score / $max * 100;
                }
            }

            if ($sessionCount > 0) {
                $percentages['attendance'][] = ($attendance[$registration->student_id] ?? 0) / $sessionCount * 100;
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

            [$letter, $point] = $this->grades->grade($total);

            return [
                'registration_id' => $registration->id,
                'student' => $registration->student,
                'breakdown' => $breakdown,
                'total' => round($total, 2),
                'letter_grade' => $letter,
                'grade_point' => $point,
            ];
        })->values();
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

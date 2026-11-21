<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SubmissionController extends Controller
{
    /** A student turns in (or re-submits) work. */
    public function store(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        Gate::authorize('submit', $assignment);
        abort_if($assignment->isPastDue() && ! $assignment->acceptsLateWork(), 422, 'The due date has passed and late work is not accepted.');

        $data = $request->validate([
            'content' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:content'],
        ]);

        $existing = Submission::where('assignment_id', $assignment->id)->where('student_id', $user->id)->first();
        abort_if($existing?->marks !== null, 422, 'This work has already been graded.');

        $filePath = $request->hasFile('file')
            ? $request->file('file')->store("classes/{$assignment->course_section_id}/submissions/{$assignment->id}", 'public')
            : $existing?->file_path;

        $submission = Submission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $user->id],
            [
                'content' => $data['content'] ?? null,
                'file_path' => $filePath,
                'submitted_at' => now(),
                'is_late' => $assignment->isPastDue(),
            ]
        );

        $penalty = $assignment->latePenaltyPercent($submission);

        return response()->json([
            ...$submission->toArray(),
            'late_penalty_percent' => $penalty,
            'message' => $penalty ? "Turned in late: {$penalty}% will be taken off your marks." : 'Turned in.',
        ], 201);
    }

    /** Teacher grades a submission. Grades flow into the gradebook automatically. */
    public function grade(Request $request, Submission $submission): JsonResponse
    {
        $assignment = $submission->assignment;
        Gate::authorize('grade', $submission);

        $assignment->loadMissing('criteria.levels');
        $hasRubric = $assignment->criteria->isNotEmpty();

        $data = $request->validate([
            // With a rubric, marks are optional: they are the sum of the chosen levels.
            'marks' => [$hasRubric ? 'nullable' : 'required', 'numeric', 'min:0', 'max:'.$assignment->max_marks],
            'rubric_scores' => [$hasRubric && ! $request->filled('marks') ? 'required' : 'nullable', 'array'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($hasRubric && ! empty($data['rubric_scores'])) {
            $data['marks'] = $this->rubricTotal($assignment, $data['rubric_scores']);
        }

        $submission->update([
            'marks' => $data['marks'],
            'rubric_scores' => $data['rubric_scores'] ?? null,
            'feedback' => $data['feedback'] ?? $submission->feedback,
            'graded_at' => now(),
            'graded_by' => $request->user()->id,
        ]);

        return response()->json([
            ...$submission->fresh()->toArray(),
            'late_penalty_percent' => $assignment->latePenaltyPercent($submission),
            'effective_marks' => $assignment->effectiveMarks($submission),
        ]);
    }

    /**
     * Sum the points of the chosen level for every criterion.
     * Each criterion needs exactly one level that belongs to it.
     *
     * @param  array<int|string, int|string>  $scores  criterion id => level id
     */
    private function rubricTotal(Assignment $assignment, array $scores): float
    {
        $total = 0.0;
        $errors = [];

        foreach ($assignment->criteria as $criterion) {
            $levelId = $scores[$criterion->id] ?? null;
            $level = $criterion->levels->firstWhere('id', (int) $levelId);

            if (! $level) {
                $errors["rubric_scores.{$criterion->id}"] = "Choose a level for \"{$criterion->title}\".";

                continue;
            }
            $total += (float) $level->points;
        }

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return round($total, 2);
    }

    /** Teacher records marks for a student who submitted on paper (e.g. mid/final exam). */
    public function gradeOffline(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('grade', $assignment);

        $data = $request->validate([
            'grades' => ['required', 'array'],
            'grades.*.student_id' => ['required', 'exists:users,id'],
            'grades.*.marks' => ['required', 'numeric', 'min:0', 'max:'.$assignment->max_marks],
        ]);

        foreach ($data['grades'] as $row) {
            Submission::updateOrCreate(
                ['assignment_id' => $assignment->id, 'student_id' => $row['student_id']],
                ['marks' => $row['marks'], 'graded_at' => now(), 'graded_by' => $request->user()->id]
            );
        }

        return response()->json(['message' => count($data['grades']).' grades saved.']);
    }
}

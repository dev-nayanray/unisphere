<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    /** A student turns in (or re-submits) work. */
    public function store(Request $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();
        abort_unless($assignment->section->isStudent($user), 403, 'Only enrolled students can submit.');
        abort_if($assignment->published_at === null, 404);
        abort_if($assignment->isPastDue() && ! $assignment->allow_late, 422, 'The due date has passed.');

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

        return response()->json($submission, 201);
    }

    /** Teacher grades a submission. Grades flow into the gradebook automatically. */
    public function grade(Request $request, Submission $submission): JsonResponse
    {
        $assignment = $submission->assignment;
        $this->ensureCanManage($assignment->section);

        $data = $request->validate([
            'marks' => ['required', 'numeric', 'min:0', 'max:'.$assignment->max_marks],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission->update([
            ...$data,
            'graded_at' => now(),
            'graded_by' => $request->user()->id,
        ]);

        return response()->json($submission);
    }

    /** Teacher records marks for a student who submitted on paper (e.g. mid/final exam). */
    public function gradeOffline(Request $request, Assignment $assignment): JsonResponse
    {
        $this->ensureCanManage($assignment->section);

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

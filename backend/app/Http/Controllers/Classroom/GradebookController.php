<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Registration;
use App\Services\ResultSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradebookController extends Controller
{
    public function __construct(private readonly ResultSyncService $results)
    {
    }

    /** Teacher: whole class. Student: only their own row. */
    public function show(Request $request, CourseSection $section): JsonResponse
    {
        $rows = $this->results->gradebook($section);

        if (! $section->canManage($request->user()) && ! $request->user()->hasRole('department_head')) {
            $rows = $rows->filter(fn ($row) => $row['student']->id === $request->user()->id)->values();
        }

        return response()->json([
            'scheme' => $section->gradingScheme(),
            'rows' => $rows,
        ]);
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

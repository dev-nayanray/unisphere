<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Registration;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClassroomController extends Controller
{
    /** "My classes": taught classes for faculty, enrolled classes for students, all for admins. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $semesterId = $request->integer('semester_id') ?: Semester::current()->value('id');

        $query = CourseSection::query()
            ->with(['course:id,code,title,credit', 'teacher:id,name,avatar', 'semester:id,name'])
            ->withCount('students')
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId));

        if ($user->isStudent()) {
            $query->whereHas('registrations', fn ($q) => $q
                ->where('student_id', $user->id)
                ->where('status', Registration::APPROVED));
        } elseif (! $user->isAdmin()) {
            $query->where('teacher_id', $user->id);
        }

        return response()->json($query->get());
    }

    public function show(Request $request, CourseSection $section): JsonResponse
    {
        $section->load(['course', 'semester:id,name', 'teacher:id,name,email,avatar'])->loadCount('students');

        return response()->json([
            ...$section->toArray(),
            'grading_scheme' => $section->gradingScheme(),
            'can_manage' => Gate::allows('manage', $section),
            // Only the teacher needs to see the join code.
            'join_code' => Gate::allows('manage', $section) ? $section->join_code : null,
        ]);
    }

    public function people(CourseSection $section): JsonResponse
    {
        return response()->json([
            'teacher' => $section->teacher()->first(['id', 'name', 'email', 'avatar']),
            'students' => $section->students()
                ->with('student:id,user_id,student_id')
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email', 'users.avatar']),
        ]);
    }

    /** Join a class with its code. Creates a pending registration for advisor approval. */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:8']]);
        $section = CourseSection::where('join_code', strtolower($data['code']))->firstOrFail();

        abort_if($section->registrations()->holdingSeat()->count() >= $section->capacity, 422, 'This class is full. Register from Course Registration to join the waitlist.');

        $registration = Registration::firstOrCreate(
            ['course_section_id' => $section->id, 'student_id' => $request->user()->id],
            ['status' => Registration::PENDING]
        );

        return response()->json([
            'message' => $registration->status === Registration::APPROVED
                ? 'You are already in this class.'
                : 'Join request sent. Your advisor will approve it.',
            'registration' => $registration,
        ]);
    }

    public function regenerateCode(CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);
        $section->update(['join_code' => CourseSection::uniqueJoinCode()]);

        return response()->json(['join_code' => $section->join_code]);
    }
}

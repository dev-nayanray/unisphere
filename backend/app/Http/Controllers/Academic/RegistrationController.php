<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Registration;
use App\Models\Result;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    /** Sections offered this semester, with seats left and the student's status. */
    public function offered(Request $request): JsonResponse
    {
        $semester = Semester::current()->firstOrFail();
        $user = $request->user();

        $sections = CourseSection::query()
            ->where('semester_id', $semester->id)
            ->when($user->student, fn ($q) => $q->whereHas('course', fn ($q) => $q->where('program_id', $user->student->program_id)))
            ->with(['course.prerequisite:id,code,title', 'teacher:id,name'])
            ->withCount('students')
            ->with(['registrations' => fn ($q) => $q->where('student_id', $user->id)])
            ->get()
            ->map(fn (CourseSection $s) => [
                ...$s->only(['id', 'section', 'room', 'schedule', 'capacity']),
                'course' => $s->course,
                'teacher' => $s->teacher,
                'seats_left' => max(0, $s->capacity - $s->students_count),
                'my_status' => $s->registrations->first()?->status,
            ]);

        return response()->json([
            'semester' => $semester,
            'max_credits' => config('unisphere.max_credits_per_semester'),
            'sections' => $sections,
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(
            Registration::where('student_id', $request->user()->id)
                ->whereHas('section.semester', fn ($q) => $q->where('is_current', true))
                ->with(['section.course:id,code,title,credit', 'section.teacher:id,name'])
                ->get()
        );
    }

    /** Register for one or more sections, with prerequisite, capacity and credit-limit checks. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $semester = Semester::current()->firstOrFail();
        abort_unless($semester->registration_open, 422, 'Course registration is closed.');

        $ids = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'exists:course_sections,id'],
        ])['section_ids'];

        $sections = CourseSection::with('course')->withCount('students')->whereIn('id', $ids)->get();

        $currentCredits = Registration::where('student_id', $user->id)
            ->whereIn('status', [Registration::PENDING, Registration::APPROVED])
            ->whereHas('section', fn ($q) => $q->where('semester_id', $semester->id))
            ->with('section.course:id,credit')
            ->get()
            ->sum(fn ($r) => (float) $r->section->course->credit);

        $errors = [];
        foreach ($sections as $section) {
            $code = $section->course->code;
            if ($section->semester_id !== $semester->id) {
                $errors[] = "{$code}: not offered this semester.";
            }
            if ($section->students_count >= $section->capacity) {
                $errors[] = "{$code}: section {$section->section} is full.";
            }
            if ($section->course->prerequisite_id && ! $this->hasPassed($user->id, $section->course->prerequisite_id)) {
                $errors[] = "{$code}: prerequisite not completed.";
            }
        }

        $newCredits = $sections->sum(fn ($s) => (float) $s->course->credit);
        if ($currentCredits + $newCredits > config('unisphere.max_credits_per_semester')) {
            $errors[] = 'Credit limit exceeded ('.config('unisphere.max_credits_per_semester').' max).';
        }

        if ($errors) {
            return response()->json(['message' => 'Registration failed.', 'errors' => ['section_ids' => $errors]], 422);
        }

        DB::transaction(function () use ($sections, $user) {
            foreach ($sections as $section) {
                Registration::firstOrCreate(
                    ['course_section_id' => $section->id, 'student_id' => $user->id],
                    ['status' => Registration::PENDING]
                );
            }
        });

        return response()->json(['message' => 'Registration submitted for advisor approval.'], 201);
    }

    public function drop(Request $request, Registration $registration): JsonResponse
    {
        abort_unless($registration->student_id === $request->user()->id, 403);
        abort_unless($registration->section->semester->registration_open, 422, 'The add/drop period is over.');

        $registration->update(['status' => Registration::DROPPED]);

        return response()->json(['message' => 'Course dropped.']);
    }

    /** Advisors, department heads and the registrar see requests waiting for approval. */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Registration::where('status', Registration::PENDING)
            ->with(['student:id,name,email', 'student.student:id,user_id,student_id,cgpa', 'section.course:id,code,title,credit'])
            ->latest();

        if (! $user->isAdmin()) {
            // Advisor sees own advisees; department head sees their department.
            $query->where(fn ($q) => $q
                ->whereHas('student.student', fn ($q) => $q->where('advisor_id', $user->id))
                ->orWhereHas('section.course.program.department', fn ($q) => $q->where('head_id', $user->id)));
        }

        return response()->json($query->paginate(30));
    }

    public function decide(Request $request, Registration $registration): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);

        $user = $request->user();
        $registration->load(['student.student', 'section.course.program.department']);
        abort_unless(
            $user->isAdmin()
                || $registration->student->student?->advisor_id === $user->id
                || $registration->section->course->program->department->head_id === $user->id,
            403,
            'Only the advisor, department head or registrar can decide this request.'
        );

        $registration->update([...$data, 'approved_by' => $request->user()->id]);

        return response()->json($registration);
    }

    private function hasPassed(int $studentId, int $courseId): bool
    {
        return Result::query()
            ->whereNotNull('published_at')
            ->where('grade_point', '>', 0)
            ->whereHas('registration', fn ($q) => $q
                ->where('student_id', $studentId)
                ->whereHas('section', fn ($q) => $q->where('course_id', $courseId)))
            ->exists();
    }
}

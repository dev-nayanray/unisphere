<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $registrations)
    {
    }

    /** Sections offered this semester, with seats left and the student's own status. */
    public function offered(Request $request): JsonResponse
    {
        $semester = Semester::current()->firstOrFail();
        $user = $request->user()->loadMissing('student');

        $sections = CourseSection::query()
            ->where('semester_id', $semester->id)
            ->when($user->student, fn ($q) => $q->whereHas('course', fn ($q) => $q->where('program_id', $user->student->program_id)))
            ->with(['course.prerequisite:id,code,title', 'teacher:id,name'])
            ->withCount(['registrations as seats_taken' => fn ($q) => $q->holdingSeat()])
            ->with(['registrations' => fn ($q) => $q->where('student_id', $user->id)])
            ->get()
            ->sortBy('course.code')
            ->values()
            ->map(fn (CourseSection $s) => [
                ...$s->only(['id', 'section', 'room', 'schedule', 'capacity']),
                'course' => $s->course,
                'teacher' => $s->teacher,
                'seats_left' => max(0, $s->capacity - $s->seats_taken),
                'my_status' => $s->registrations->first()?->status,
                'my_note' => $s->registrations->first()?->note,
            ]);

        return response()->json([
            'semester' => $semester,
            'open' => $semester->acceptsRegistration(),
            'min_credits' => config('unisphere.min_credits_per_semester'),
            'max_credits' => config('unisphere.max_credits_per_semester'),
            'sections' => $sections,
        ]);
    }

    /** This semester's registrations, with waitlist position and the advisor's note. */
    public function mine(Request $request): JsonResponse
    {
        $rows = Registration::where('student_id', $request->user()->id)
            ->whereHas('section.semester', fn ($q) => $q->where('is_current', true))
            ->with(['section.course:id,code,title,credit', 'section.teacher:id,name', 'approver:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Registration $r) => [...$r->toArray(), 'waitlist_position' => $this->registrations->waitlistPosition($r)]);

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $semester = Semester::current()->firstOrFail();

        $ids = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct', 'exists:course_sections,id'],
        ])['section_ids'];

        $sections = CourseSection::with(['course.prerequisite:id,code'])->whereIn('id', $ids)->get();
        $result = $this->registrations->register($request->user(), $semester, $sections);

        if ($result['errors']) {
            return response()->json(['message' => 'Some courses could not be registered.', 'errors' => $result['errors']], 422);
        }

        $message = $result['registered']->count().' course(s) sent for advisor approval.';
        if ($result['waitlisted']->isNotEmpty()) {
            $message .= ' '.$result['waitlisted']->count().' full course(s) added to the waitlist.';
        }

        return response()->json(['message' => $message], 201);
    }

    public function drop(Registration $registration): JsonResponse
    {
        Gate::authorize('drop', $registration);
        abort_unless($registration->section->semester->acceptsRegistration(), 422, 'The add/drop period is over.');
        abort_if($registration->status === Registration::DROPPED, 422, 'This course is already dropped.');

        $this->registrations->drop($registration);

        return response()->json(['message' => 'Course dropped.']);
    }

    /** Requests waiting for the signed-in advisor / head / registrar. */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Registration::where('status', Registration::PENDING)
            ->with(['student:id,name,email', 'student.student:id,user_id,student_id,cgpa,advisor_id', 'section.course:id,code,title,credit'])
            ->orderBy('created_at');

        if (! $user->isAdmin()) {
            $query->where(fn ($q) => $q
                ->whereHas('student.student', fn ($q) => $q->where('advisor_id', $user->id))
                ->orWhereHas('section.course.program.department', fn ($q) => $q->where('head_id', $user->id)));
        }

        return response()->json($query->paginate(50));
    }

    public function decide(Request $request, Registration $registration): JsonResponse
    {
        $data = $this->decision($request);
        Gate::authorize('decide', $registration);
        abort_unless($registration->status === Registration::PENDING, 422, 'Only pending requests can be decided.');

        $this->registrations->decide($registration, $data['status'], $data['note'] ?? null, $request->user());

        return response()->json($registration->fresh(['section.course:id,code']));
    }

    /** Approve or reject many requests at once (e.g. a whole student's semester). */
    public function decideMany(Request $request): JsonResponse
    {
        $data = $this->decision($request) + $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:registrations,id'],
        ]);

        $registrations = Registration::whereIn('id', $data['ids'])->where('status', Registration::PENDING)->get();
        foreach ($registrations as $registration) {
            Gate::authorize('decide', $registration);
        }

        foreach ($registrations as $registration) {
            $this->registrations->decide($registration, $data['status'], $data['note'] ?? null, $request->user());
        }

        return response()->json(['message' => $registrations->count().' request(s) '.$data['status'].'.']);
    }

    /** Registrar override: add a student to a section even when it is full. */
    public function forceAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student' => ['required', 'string'], // student ID or email
            'section_id' => ['required', 'exists:course_sections,id'],
        ]);

        $student = User::where('role', 'student')
            ->where(fn ($q) => $q->where('email', $data['student'])
                ->orWhereHas('student', fn ($q) => $q->where('student_id', $data['student'])))
            ->first();
        abort_unless($student, 422, 'No student found with that ID or email.');

        $registration = $this->registrations->forceAdd($student, CourseSection::findOrFail($data['section_id']), $request->user());

        return response()->json(['message' => "{$student->name} added to the section.", 'registration' => $registration], 201);
    }

    private function decision(Request $request): array
    {
        return $request->validate([
            'status' => ['required', Rule::in([Registration::APPROVED, Registration::REJECTED])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}

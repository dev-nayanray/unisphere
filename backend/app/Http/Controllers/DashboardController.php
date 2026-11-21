<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\Complaint;
use App\Models\CourseSection;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\RoutineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One endpoint that returns the right dashboard for the signed-in role. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $semester = Semester::current()->first();

        $data = match ($user->role) {
            Role::Student => $this->student($user),
            Role::Teacher => $this->teacher($user, $semester),
            Role::Accounts => $this->accounts(),
            default => $this->admin($semester),
        };

        return response()->json([
            'semester' => $semester,
            'today' => in_array($user->role, [Role::Student, Role::Teacher, Role::DepartmentHead], true)
                ? app(RoutineService::class)->today($user)
                : [],
            'notices' => Notice::visibleTo($user)->latest('published_at')->limit(5)->get(['id', 'title', 'is_urgent', 'published_at']),
            ...$data,
        ]);
    }

    private function student(User $user): array
    {
        $sectionIds = $user->enrolledSections()->pluck('course_sections.id');

        $pending = Assignment::query()
            ->whereIn('course_section_id', $sectionIds)
            ->whereNotNull('published_at')
            ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $user->id))
            ->with('section.course:id,code,title')
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $records = AttendanceRecord::where('student_id', $user->id)->get(['status']);
        $attendanceRate = $records->count()
            ? round($records->whereIn('status', ['present', 'late'])->count() / $records->count() * 100, 1)
            : null;

        return [
            'stats' => [
                'enrolled_courses' => $sectionIds->count(),
                'pending_work' => $pending->count(),
                'cgpa' => (float) ($user->student?->cgpa ?? 0),
                'attendance_rate' => $attendanceRate,
                'fees_due' => (float) Invoice::where('student_id', $user->id)->where('status', '!=', 'paid')->get()->sum('due_amount'),
            ],
            'todo' => $pending,
        ];
    }

    private function teacher(User $user, ?Semester $semester): array
    {
        $sections = CourseSection::query()
            ->where('teacher_id', $user->id)
            ->when($semester, fn ($q) => $q->where('semester_id', $semester->id))
            ->with('course:id,code,title')
            ->withCount(['students', 'assignments'])
            ->get();

        $toGrade = \App\Models\Submission::query()
            ->whereNull('marks')
            ->whereHas('assignment', fn ($q) => $q->whereIn('course_section_id', $sections->pluck('id')))
            ->count();

        return [
            'stats' => [
                'classes' => $sections->count(),
                'students' => $sections->sum('students_count'),
                'to_grade' => $toGrade,
            ],
            'classes' => $sections,
        ];
    }

    private function accounts(): array
    {
        return [
            'stats' => [
                'unpaid_invoices' => Invoice::where('status', '!=', 'paid')->count(),
                'collected_this_month' => (float) Payment::where('status', 'success')
                    ->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'pending_verifications' => Payment::where('status', 'pending')->count(),
            ],
        ];
    }

    private function admin(?Semester $semester): array
    {
        $atRisk = Student::query()->where('cgpa', '>', 0)->where('cgpa', '<', 2.5)->count();

        return [
            'stats' => [
                'students' => User::where('role', Role::Student->value)->count(),
                'faculty' => User::whereIn('role', Role::faculty())->count(),
                'sections' => $semester ? CourseSection::where('semester_id', $semester->id)->count() : 0,
                'pending_registrations' => Registration::where('status', Registration::PENDING)->count(),
                'open_complaints' => Complaint::where('status', '!=', 'resolved')->count(),
                'at_risk_students' => $atRisk,
            ],
        ];
    }
}

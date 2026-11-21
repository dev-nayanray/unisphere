<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Registration;
use App\Models\Result;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    /** Student: published results grouped by semester, with GPA per semester and CGPA. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $results = Result::query()
            ->whereNotNull('published_at')
            ->whereHas('registration', fn ($q) => $q->where('student_id', $user->id))
            ->with('registration.section.course:id,code,title,credit', 'registration.section.semester:id,name,start_date')
            ->get();

        $semesters = $results
            ->groupBy(fn (Result $r) => $r->registration->section->semester->id)
            ->map(function ($rows) {
                $semester = $rows->first()->registration->section->semester;
                $credits = $rows->sum(fn ($r) => (float) $r->registration->section->course->credit);
                $points = $rows->sum(fn ($r) => (float) $r->grade_point * (float) $r->registration->section->course->credit);

                return [
                    'semester' => $semester,
                    'gpa' => $credits ? round($points / $credits, 2) : 0,
                    'credits' => $credits,
                    'courses' => $rows->map(fn ($r) => [
                        'code' => $r->registration->section->course->code,
                        'title' => $r->registration->section->course->title,
                        'credit' => (float) $r->registration->section->course->credit,
                        'total' => (float) $r->total,
                        'letter_grade' => $r->letter_grade,
                        'grade_point' => (float) $r->grade_point,
                    ])->values(),
                ];
            })
            ->sortBy(fn ($s) => $s['semester']->start_date)
            ->values();

        return response()->json([
            'cgpa' => (float) ($user->student?->cgpa ?? 0),
            'credits_completed' => $semesters->sum('credits'),
            'semesters' => $semesters,
        ]);
    }

    /** Teacher / department head: results for one section, including drafts. */
    public function section(CourseSection $section): JsonResponse
    {
        $this->ensureCanView($section);
        abort_if(request()->user()->isStudent(), 403);

        return response()->json(
            Result::whereHas('registration', fn ($q) => $q->where('course_section_id', $section->id))
                ->with('registration.student:id,name,email')
                ->get()
        );
    }

    /** Department head or registrar publishes the results and CGPAs update. */
    public function publish(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanView($section);

        $count = Result::whereHas('registration', fn ($q) => $q->where('course_section_id', $section->id))
            ->whereNull('published_at')
            ->update(['published_at' => now(), 'published_by' => $request->user()->id]);

        $studentIds = Registration::where('course_section_id', $section->id)->pluck('student_id');
        Student::whereIn('user_id', $studentIds)->get()->each->recalculateCgpa();

        return response()->json(['message' => "{$count} results published."]);
    }
}

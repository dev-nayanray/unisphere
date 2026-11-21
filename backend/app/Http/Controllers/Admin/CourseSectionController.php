<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Course offering: create sections (= classrooms) for a semester and assign teachers. */
class CourseSectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            CourseSection::with(['course:id,code,title,credit', 'semester:id,name', 'teacher:id,name'])
                ->withCount('students')
                ->when($request->semester_id, fn ($q, $id) => $q->where('semester_id', $id))
                ->orderBy('course_id')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $section = CourseSection::create($this->validated($request));

        return response()->json($section->load(['course', 'semester', 'teacher:id,name']), 201);
    }

    public function show(CourseSection $section): JsonResponse
    {
        return response()->json($section->load(['course', 'semester', 'teacher:id,name']));
    }

    public function update(Request $request, CourseSection $section): JsonResponse
    {
        $section->update($this->validated($request, $section));

        return response()->json($section);
    }

    public function destroy(CourseSection $section): JsonResponse
    {
        $section->delete();

        return response()->json(['message' => 'Section deleted.']);
    }

    private function validated(Request $request, ?CourseSection $section = null): array
    {
        $req = $section ? 'sometimes' : 'required';

        return $request->validate([
            'course_id' => [$req, 'exists:courses,id'],
            'semester_id' => [$req, 'exists:semesters,id'],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'section' => [$req, 'string', 'max:10'],
            'room' => ['nullable', 'string', 'max:30'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'schedule' => ['nullable', 'array'],
            'schedule.*.day' => ['required_with:schedule', 'in:Sat,Sun,Mon,Tue,Wed,Thu,Fri'],
            'schedule.*.start' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.end' => ['required_with:schedule', 'date_format:H:i'],
            'grading_scheme' => ['nullable', 'array'],
            'grading_scheme.*' => ['numeric', 'min:0', 'max:100'],
            'cover_color' => ['sometimes', 'string', 'max:20'],
        ]);
    }
}

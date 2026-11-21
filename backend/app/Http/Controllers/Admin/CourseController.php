<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        return $this->listing(
            $request,
            Course::with(['program:id,code', 'prerequisite:id,code'])
                ->withCount('sections')
                ->when($request->program_id, fn ($q, $id) => $q->where('program_id', $id)),
            searchable: ['code', 'title'],
            sortable: ['code', 'title', 'credit'],
            defaultSort: 'code',
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Course::create($this->validated($request)), 201);
    }

    /** Includes where the course is used: its sections and the courses that require it. */
    public function show(Course $course): JsonResponse
    {
        $course->load([
            'program:id,code,name',
            'prerequisite:id,code,title',
            'sections' => fn ($q) => $q->with(['semester:id,name', 'teacher:id,name'])->withCount('students'),
        ]);

        return response()->json([
            ...$course->toArray(),
            'required_by' => Course::where('prerequisite_id', $course->id)->get(['id', 'code', 'title']),
        ]);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $course->update($this->validated($request, $course));

        return response()->json($course->load(['program:id,code', 'prerequisite:id,code']));
    }

    public function destroy(Course $course): JsonResponse
    {
        $registrations = Registration::whereHas('section', fn ($q) => $q->where('course_id', $course->id))->count();

        if ($registrations) {
            return response()->json([
                'message' => "{$course->code} has {$registrations} student registration(s) and cannot be deleted.",
            ], 422);
        }

        return $this->deleteOrExplain($course, 'course');
    }

    private function validated(Request $request, ?Course $course = null): array
    {
        $req = $course ? 'sometimes' : 'required';

        return $request->validate([
            'program_id' => [$req, 'exists:programs,id'],
            'prerequisite_id' => ['nullable', 'exists:courses,id', ...($course ? [Rule::notIn([$course->id])] : [])],
            'code' => [$req, 'string', 'max:20', Rule::unique('courses')->ignore($course)],
            'title' => [$req, 'string', 'max:200'],
            'credit' => [$req, 'numeric', 'min:0.5', 'max:6'],
            'description' => ['nullable', 'string'],
        ], [
            'prerequisite_id.not_in' => 'A course cannot be its own prerequisite.',
        ]);
    }
}

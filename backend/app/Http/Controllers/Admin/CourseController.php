<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Course::with(['program:id,code', 'prerequisite:id,code'])
                ->when($request->program_id, fn ($q, $id) => $q->where('program_id', $id))
                ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                    ->where('code', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
                ->orderBy('code')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Course::create($this->validated($request)), 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json($course->load(['program', 'prerequisite', 'sections.semester']));
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $course->update($this->validated($request, $course));

        return response()->json($course);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }

    private function validated(Request $request, ?Course $course = null): array
    {
        $req = $course ? 'sometimes' : 'required';

        return $request->validate([
            'program_id' => [$req, 'exists:programs,id'],
            'prerequisite_id' => ['nullable', 'exists:courses,id'],
            'code' => [$req, 'string', 'max:20', Rule::unique('courses')->ignore($course)],
            'title' => [$req, 'string', 'max:200'],
            'credit' => [$req, 'numeric', 'min:0.5', 'max:6'],
            'description' => ['nullable', 'string'],
        ]);
    }
}

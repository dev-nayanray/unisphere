<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Course offering: create sections (= classrooms) for a semester and assign teachers. */
class CourseSectionController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');

        return $this->listing(
            // Search is applied here (on the course), so it is not passed to listing().
            $request->merge(['search' => null]),
            CourseSection::with(['course:id,code,title,credit', 'semester:id,name', 'teacher:id,name'])
                ->withCount('students')
                ->when($request->semester_id, fn ($q, $id) => $q->where('semester_id', $id))
                ->when($search, fn ($q, $s) => $q->whereHas('course', fn ($q) => $q
                    ->where('code', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%"))),
            sortable: ['section', 'room', 'capacity', 'course_id'],
            defaultSort: 'course_id',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $section = CourseSection::create($this->validated($request));

        return response()->json($section->load(['course', 'semester', 'teacher:id,name']), 201);
    }

    public function show(CourseSection $section): JsonResponse
    {
        $section->load(['course', 'semester', 'teacher:id,name'])->loadCount('students');

        return response()->json([...$section->toArray(), 'grading_scheme' => $section->gradingScheme()]);
    }

    public function update(Request $request, CourseSection $section): JsonResponse
    {
        $data = $this->validated($request, $section);

        $enrolled = $section->students()->count();
        if (isset($data['capacity']) && $data['capacity'] < $enrolled) {
            throw ValidationException::withMessages([
                'capacity' => "Capacity cannot be lower than the number of enrolled students ({$enrolled}).",
            ]);
        }

        $section->update($data);

        return response()->json($section->load(['course:id,code,title,credit', 'semester:id,name', 'teacher:id,name']));
    }

    public function destroy(CourseSection $section): JsonResponse
    {
        $count = $section->registrations()->count();
        if ($count) {
            return response()->json([
                'message' => "This section has {$count} registration(s). Move the students to another section first.",
            ], 422);
        }

        return $this->deleteOrExplain($section, 'section');
    }

    private function validated(Request $request, ?CourseSection $section = null): array
    {
        $req = $section ? 'sometimes' : 'required';

        $data = $request->validate([
            'course_id' => [$req, 'exists:courses,id'],
            'semester_id' => [$req, 'exists:semesters,id'],
            'teacher_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', Role::faculty())],
            'section' => [$req, 'string', 'max:10'],
            'room' => ['nullable', 'string', 'max:30'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'schedule' => ['nullable', 'array', 'max:7'],
            'schedule.*.day' => ['required', 'in:Sat,Sun,Mon,Tue,Wed,Thu,Fri'],
            'schedule.*.start' => ['required', 'date_format:H:i'],
            'schedule.*.end' => ['required', 'date_format:H:i'],
            'grading_scheme' => ['nullable', 'array'],
            'grading_scheme.*' => ['numeric', 'min:0', 'max:100'],
            'cover_color' => ['sometimes', 'in:indigo,emerald,amber,rose,sky'],
        ], [
            'teacher_id.exists' => 'The teacher must be a teacher or department head account.',
        ]);

        foreach ($data['schedule'] ?? [] as $i => $slot) {
            if ($slot['end'] <= $slot['start']) {
                throw ValidationException::withMessages(["schedule.{$i}.end" => 'Each class must end after it starts.']);
            }
        }

        if (! empty($data['grading_scheme'])) {
            $unknown = array_diff(array_keys($data['grading_scheme']), array_keys(config('unisphere.default_grading_scheme')));
            if ($unknown) {
                throw ValidationException::withMessages(['grading_scheme' => 'Unknown grading categories: '.implode(', ', $unknown).'.']);
            }
            if (abs(array_sum($data['grading_scheme']) - 100) > 0.01) {
                throw ValidationException::withMessages(['grading_scheme' => 'Grading weights must add up to 100.']);
            }
        }

        // The same course can't have two sections with the same label in one semester.
        $courseId = $data['course_id'] ?? $section?->course_id;
        $semesterId = $data['semester_id'] ?? $section?->semester_id;
        $label = $data['section'] ?? $section?->section;

        $taken = CourseSection::query()
            ->where(['course_id' => $courseId, 'semester_id' => $semesterId, 'section' => $label])
            ->when($section, fn ($q) => $q->whereKeyNot($section->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['section' => "Section {$label} of this course already exists this semester."]);
        }

        return $data;
    }
}

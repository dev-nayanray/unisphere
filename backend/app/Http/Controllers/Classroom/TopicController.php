<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Classwork topics: create, rename, delete (work moves to "No topic") and reorder. */
class TopicController extends Controller
{
    public function index(CourseSection $section): JsonResponse
    {
        return response()->json($section->topics()->get(['id', 'name', 'position']));
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('topics')->where('course_section_id', $section->id)],
        ]);

        $topic = $section->topics()->create([
            'name' => $data['name'],
            'position' => (int) $section->topics()->max('position') + 1,
        ]);

        return response()->json($topic, 201);
    }

    public function update(Request $request, Topic $topic): JsonResponse
    {
        $this->ensureCanManage($topic->section);

        $topic->update($request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('topics')->where('course_section_id', $topic->course_section_id)->ignore($topic)],
        ]));

        return response()->json($topic);
    }

    public function destroy(Topic $topic): JsonResponse
    {
        $this->ensureCanManage($topic->section);
        $topic->delete(); // classwork keeps existing; topic_id becomes null

        return response()->json(['message' => 'Topic deleted. Its classwork moved to "No topic".']);
    }

    /** Save a new order after drag and drop: { ids: [3, 1, 2] }. */
    public function reorder(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('topics', 'id')->where('course_section_id', $section->id)],
        ])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                Topic::whereKey($id)->update(['position' => $position]);
            }
        });

        return response()->json($section->topics()->get(['id', 'name', 'position']));
    }
}

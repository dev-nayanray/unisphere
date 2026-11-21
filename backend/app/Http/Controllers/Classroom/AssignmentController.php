<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseSection;
use App\Models\Post;
use App\Notifications\NewClassworkPosted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    /** Classwork list. Students see published work and their own submission status. */
    public function index(Request $request, CourseSection $section): JsonResponse
    {
        $user = $request->user();
        $manage = Gate::allows('manage', $section);

        $assignments = $section->assignments()
            ->when(! $manage, fn ($q) => $q->whereNotNull('published_at'))
            ->when($manage, fn ($q) => $q->withCount([
                'submissions',
                'submissions as graded_count' => fn ($q) => $q->whereNotNull('marks'),
            ]))
            ->when(! $manage, fn ($q) => $q->with(['submissions' => fn ($q) => $q->where('student_id', $user->id)]))
            ->orderByRaw('due_at IS NULL, due_at')
            ->get();

        return response()->json($assignments);
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $this->ensureCanManage($section);

        $data = $this->validated($request);
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store("classes/{$section->id}/assignments", 'public');
        }
        unset($data['attachment'], $data['publish']);

        $assignment = $section->assignments()->create([
            ...$data,
            'author_id' => $request->user()->id,
            'published_at' => $request->boolean('publish', true) ? now() : null,
        ]);

        if ($assignment->published_at) {
            Notification::send($section->students()->get(), new NewClassworkPosted($assignment));
            Post::announceClasswork($section, $request->user(), 'assignment', $assignment->id, $assignment->title);
        }

        return response()->json($assignment, 201);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);
        $section = $assignment->section;
        $user = $request->user();

        if (Gate::allows('update', $assignment)) {
            $assignment->load(['submissions.student:id,name,email']);
            $assignment->setAttribute('roster', $section->students()->get(['users.id', 'users.name']));
        } else {
            $assignment->load(['submissions' => fn ($q) => $q->where('student_id', $user->id)]);
        }

        return response()->json($assignment->load('section.course:id,code,title'));
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        $data = $this->validated($request, partial: true);
        unset($data['attachment'], $data['publish']);
        $wasDraft = $assignment->published_at === null;
        if ($request->has('publish')) {
            $data['published_at'] = $request->boolean('publish') ? ($assignment->published_at ?? now()) : null;
        }
        $assignment->update($data);

        // Publishing a draft announces it, exactly like publishing on creation.
        if ($wasDraft && $assignment->published_at) {
            $section = $assignment->section;
            Notification::send($section->students()->get(), new NewClassworkPosted($assignment));
            Post::announceClasswork($section, $request->user(), 'assignment', $assignment->id, $assignment->title);
        }

        return response()->json($assignment);
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        Gate::authorize('delete', $assignment);
        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'instructions' => ['nullable', 'string'],
            'topic' => ['nullable', 'string', 'max:80'],
            'category' => ['sometimes', Rule::in(Assignment::CATEGORIES)],
            'max_marks' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            'due_at' => ['nullable', 'date'],
            'allow_late' => ['sometimes', 'boolean'],
            'publish' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:20480'],
        ]);
    }
}

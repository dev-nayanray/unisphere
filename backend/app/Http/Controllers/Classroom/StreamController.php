<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The class stream: announcements and comments. */
class StreamController extends Controller
{
    public function index(CourseSection $section): JsonResponse
    {
        return response()->json(
            $section->posts()
                ->with(['author:id,name,avatar,role', 'comments.author:id,name,avatar'])
                ->orderByDesc('is_pinned')
                ->latest()
                ->paginate(15)
        );
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        // Students may post too (like Google Classroom), but only teachers can pin.
        if (! $section->canManage($request->user())) {
            $data['is_pinned'] = false;
        }

        $post = $section->posts()->create([...$data, 'author_id' => $request->user()->id]);

        return response()->json($post->load('author:id,name,avatar,role'), 201);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $this->authorizeOwnerOrTeacher($request, $post);

        $post->update($request->validate([
            'body' => ['sometimes', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]));

        return response()->json($post);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorizeOwnerOrTeacher($request, $post);
        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    public function comment(Request $request, Post $post): JsonResponse
    {
        $this->ensureCanView($post->section);

        $comment = $post->comments()->create([
            'author_id' => $request->user()->id,
            'body' => $request->validate(['body' => ['required', 'string', 'max:2000']])['body'],
        ]);

        return response()->json($comment->load('author:id,name,avatar'), 201);
    }

    private function authorizeOwnerOrTeacher(Request $request, Post $post): void
    {
        abort_unless(
            $post->author_id === $request->user()->id || $post->section->canManage($request->user()),
            403
        );
    }
}

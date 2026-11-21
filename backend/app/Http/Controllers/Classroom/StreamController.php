<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseSection;
use App\Models\Post;
use App\Models\PostAttachment;
use App\Models\PostComment;
use App\Models\Quiz;
use App\Notifications\NewStreamPost;
use App\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The class stream: announcements, comments, attachments. */
class StreamController extends Controller
{
    public const MAX_ATTACHMENTS = 5;

    public function index(Request $request, CourseSection $section): JsonResponse
    {
        $manage = Gate::allows('manage', $section);

        $posts = $section->posts()
            // Teachers also see their scheduled posts; everyone else sees published ones.
            ->when(! $manage, fn ($q) => $q->published())
            ->with(['author:id,name,avatar,role', 'comments.author:id,name,avatar', 'attachments'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json([
            ...$posts->toArray(),
            'can_post' => Gate::allows('create', [Post::class, $section]),
            'stream_permission' => $section->stream_permission,
        ]);
    }

    public function store(Request $request, CourseSection $section): JsonResponse
    {
        Gate::authorize('create', [Post::class, $section]);
        $manage = Gate::allows('manage', $section);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
        ]);

        // Only teachers can pin or schedule.
        $scheduledAt = $manage ? ($data['scheduled_at'] ?? null) : null;

        $post = $section->posts()->create([
            'author_id' => $request->user()->id,
            'body' => SafeText::clean($data['body']),
            'is_pinned' => $manage && $request->boolean('is_pinned'),
            'type' => Post::TYPE_ANNOUNCEMENT,
            'published_at' => $scheduledAt ?? now(),
        ]);

        foreach ($request->file('attachments', []) as $file) {
            $post->attachments()->create([
                'path' => $file->store("classes/{$section->id}/posts/{$post->id}", 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }

        if (! $scheduledAt) {
            $this->notify($post);
        }

        return response()->json($post->load(['author:id,name,avatar,role', 'attachments']), 201);
    }

    /** Edit the text (author) and/or pin (teacher). */
    public function update(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate([
            'body' => ['sometimes', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_pinned', $data) && ! array_key_exists('body', $data)) {
            Gate::authorize('pin', $post);
        } else {
            Gate::authorize('update', $post);
            if (array_key_exists('is_pinned', $data)) {
                Gate::authorize('pin', $post);
            }
        }

        if (array_key_exists('body', $data)) {
            $data['body'] = SafeText::clean($data['body']);
            $data['edited_at'] = now();
        }

        $post->update($data);

        return response()->json($post->fresh(['author:id,name,avatar,role', 'attachments']));
    }

    public function destroy(Post $post): JsonResponse
    {
        Gate::authorize('delete', $post);

        foreach ($post->attachments as $attachment) {
            Storage::disk('local')->delete($attachment->path);
        }
        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    public function comment(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('comment', $post);

        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];

        $comment = $post->comments()->create([
            'author_id' => $request->user()->id,
            'body' => SafeText::clean($body),
        ]);

        return response()->json($comment->load('author:id,name,avatar'), 201);
    }

    public function updateComment(Request $request, PostComment $comment): JsonResponse
    {
        Gate::authorize('update', $comment);

        $comment->update([
            'body' => SafeText::clean($request->validate(['body' => ['required', 'string', 'max:2000']])['body']),
            'edited_at' => now(),
        ]);

        return response()->json($comment->load('author:id,name,avatar'));
    }

    public function destroyComment(PostComment $comment): JsonResponse
    {
        Gate::authorize('delete', $comment);
        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    /** Signed link (no login token needed) so images and PDFs can preview inline. */
    public function attachment(PostAttachment $attachment): StreamedResponse
    {
        $inline = $attachment->is_image || $attachment->is_pdf;

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff'],
            $inline ? 'inline' : 'attachment',
        );
    }

    /** Teacher setting: what students may do in the stream. */
    public function settings(Request $request, CourseSection $section): JsonResponse
    {
        Gate::authorize('manage', $section);

        $section->update($request->validate([
            'stream_permission' => ['required', Rule::in([
                CourseSection::STREAM_POST_COMMENT, CourseSection::STREAM_COMMENT, CourseSection::STREAM_NONE,
            ])],
        ]));

        return response()->json(['stream_permission' => $section->stream_permission]);
    }

    /** "Upcoming" sidebar: the next three due assignments and quizzes. */
    public function upcoming(CourseSection $section): JsonResponse
    {
        $assignments = Assignment::where('course_section_id', $section->id)
            ->whereNotNull('published_at')
            ->where('due_at', '>=', now())
            ->orderBy('due_at')
            ->limit(3)
            ->get(['id', 'title', 'due_at'])
            ->map(fn ($a) => ['kind' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'due_at' => $a->due_at]);

        $quizzes = Quiz::where('course_section_id', $section->id)
            ->whereNotNull('published_at')
            ->where('ends_at', '>=', now())
            ->orderBy('ends_at')
            ->limit(3)
            ->get(['id', 'title', 'ends_at'])
            ->map(fn ($q) => ['kind' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'due_at' => $q->ends_at]);

        return response()->json($assignments->concat($quizzes)->sortBy('due_at')->take(3)->values());
    }

    /** Tell the rest of the class about a new teacher announcement. */
    private function notify(Post $post): void
    {
        $section = $post->section;
        if ($post->author_id !== $section->teacher_id) {
            return; // student posts don't notify everyone
        }

        Notification::send($section->students()->get(), new NewStreamPost($post));
        $post->update(['notified_at' => now()]);
    }
}

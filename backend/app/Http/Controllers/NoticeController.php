<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use App\Models\User;
use App\Notifications\NoticePublished;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class NoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Notice::visibleTo($request->user())
                ->with(['author:id,name', 'department:id,code'])
                ->when($request->boolean('urgent'), fn ($q) => $q->where('is_urgent', true))
                ->orderByDesc('is_urgent')
                ->latest('published_at')
                ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'audience' => ['required', 'in:all,students,faculty'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'is_urgent' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('notices', 'public');
        }
        unset($data['attachment']);

        $notice = Notice::create([...$data, 'author_id' => $request->user()->id, 'published_at' => now()]);

        // Notify the audience in-app. Swap to a queued job for large universities.
        $recipients = User::query()
            ->where('is_active', true)
            ->when($notice->audience === 'students', fn ($q) => $q->where('role', 'student'))
            ->when($notice->audience === 'faculty', fn ($q) => $q->where('role', '!=', 'student'))
            ->get();
        Notification::send($recipients, new NoticePublished($notice));

        return response()->json($notice, 201);
    }

    public function show(Request $request, Notice $notice): JsonResponse
    {
        abort_unless(Notice::visibleTo($request->user())->whereKey($notice->id)->exists(), 404);

        return response()->json($notice->load(['author:id,name', 'department:id,code,name']));
    }

    public function destroy(Request $request, Notice $notice): JsonResponse
    {
        abort_unless($request->user()->isAdmin() || $notice->author_id === $request->user()->id, 403);
        $notice->delete();

        return response()->json(['message' => 'Notice deleted.']);
    }
}

<?php

namespace App\Http\Controllers\Classroom;

use App\Http\Controllers\Controller;
use App\Models\FeedbackComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The signed-in teacher's comment bank: reusable feedback snippets. */
class FeedbackCommentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(FeedbackComment::where('teacher_id', $request->user()->id)->latest()->get(['id', 'text']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);

        $comment = FeedbackComment::firstOrCreate(['teacher_id' => $request->user()->id, 'text' => trim($data['text'])]);

        return response()->json($comment->only(['id', 'text']), 201);
    }

    public function destroy(FeedbackComment $feedback): JsonResponse
    {
        Gate::authorize('delete', $feedback);
        $feedback->delete();

        return response()->json(['message' => 'Removed from your comment bank.']);
    }
}

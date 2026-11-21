<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Help desk tickets: Pending -> In progress -> Resolved. */
class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            Complaint::query()
                ->with(['user:id,name,role', 'assignee:id,name'])
                ->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($q) => $q
                    ->where('user_id', $user->id)
                    ->orWhere('assigned_to', $user->id)))
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->latest()
                ->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(Complaint::CATEGORIES)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json(Complaint::create([...$data, 'user_id' => $request->user()->id]), 201);
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        $this->authorizeView($request, $complaint);

        return response()->json($complaint->load(['user:id,name,email', 'assignee:id,name']));
    }

    /** Admins assign and resolve; the assignee can update the status. */
    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $complaint->assigned_to === $user->id, 403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Complaint::STATUSES)],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);

        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_at'] = now();
        }

        $complaint->update($data);

        return response()->json($complaint);
    }

    private function authorizeView(Request $request, Complaint $complaint): void
    {
        $user = $request->user();
        abort_unless(
            $user->isAdmin() || $complaint->user_id === $user->id || $complaint->assigned_to === $user->id,
            403
        );
    }
}

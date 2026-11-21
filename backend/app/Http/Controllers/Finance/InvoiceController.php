<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /** Students see their own invoices; accounts staff see everything. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->with(['student:id,name,email', 'semester:id,name', 'payments'])
            ->when($user->isStudent(), fn ($q) => $q->where('student_id', $user->id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(25);

        return response()->json($invoices);
    }

    /** Create one invoice, or bulk-bill every student in a batch/program. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:1'],
            'waiver' => ['sometimes', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'semester_id' => ['nullable', 'exists:semesters,id'],
            'student_id' => ['required_without:program_id', 'nullable', 'exists:users,id'],
            'program_id' => ['required_without:student_id', 'nullable', 'exists:programs,id'],
            'batch' => ['nullable', 'string'],
        ]);

        $studentIds = ! empty($data['student_id'])
            ? [$data['student_id']]
            : User::whereHas('student', fn ($q) => $q
                ->where('program_id', $data['program_id'])
                ->when($data['batch'] ?? null, fn ($q, $b) => $q->where('batch', $b)))
                ->pluck('id')->all();

        $fields = collect($data)->only(['title', 'amount', 'waiver', 'due_date', 'semester_id'])->all();

        DB::transaction(function () use ($studentIds, $fields) {
            foreach ($studentIds as $id) {
                Invoice::create([...$fields, 'student_id' => $id]);
            }
        });

        return response()->json(['message' => count($studentIds).' invoice(s) created.'], 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);

        return response()->json($invoice->load(['student:id,name,email', 'semester:id,name', 'payments']));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $invoice->update($request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'amount' => ['sometimes', 'numeric', 'min:1'],
            'waiver' => ['sometimes', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
        ]));
        $invoice->refreshStatus();

        return response()->json($invoice);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SemesterController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        return $this->listing(
            $request,
            Semester::withCount('sections'),
            searchable: ['name'],
            sortable: ['name', 'start_date', 'end_date'],
            defaultSort: 'start_date',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $semester = Semester::create($this->validated($request));
        if ($semester->is_current) {
            $semester->makeCurrent();
        }

        return response()->json($semester, 201);
    }

    public function show(Semester $semester): JsonResponse
    {
        return response()->json($semester->loadCount('sections'));
    }

    public function update(Request $request, Semester $semester): JsonResponse
    {
        $data = $this->validated($request, $semester);

        // When only one date is sent, compare it with the stored one.
        $start = $data['start_date'] ?? $semester->start_date->toDateString();
        $end = $data['end_date'] ?? $semester->end_date->toDateString();
        if (strtotime($end) <= strtotime($start)) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be after the start date.']);
        }

        $semester->update($data);
        if ($semester->is_current) {
            $semester->makeCurrent();
        }

        return response()->json($semester->fresh());
    }

    public function makeCurrent(Semester $semester): JsonResponse
    {
        $semester->makeCurrent();

        return response()->json([
            'message' => "{$semester->name} is now the current semester.",
            'semester' => $semester->fresh(),
        ]);
    }

    public function destroy(Semester $semester): JsonResponse
    {
        if ($semester->is_current) {
            return response()->json(['message' => 'You cannot delete the current semester. Make another semester current first.'], 422);
        }

        $registrations = Registration::whereHas('section', fn ($q) => $q->where('semester_id', $semester->id))->count();
        if ($registrations) {
            return response()->json([
                'message' => "{$semester->name} has {$registrations} course registration(s) and cannot be deleted.",
            ], 422);
        }

        return $this->deleteOrExplain($semester, 'semester');
    }

    private function validated(Request $request, ?Semester $semester = null): array
    {
        $req = $semester ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:40'],
            'start_date' => [$req, 'date'],
            'end_date' => [$req, 'date', ...($semester ? [] : ['after:start_date'])],
            'registration_open' => ['sometimes', 'boolean'],
            'registration_deadline' => ['nullable', 'date'],
            'is_current' => ['sometimes', 'boolean'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SemesterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Semester::withCount('sections')->latest('start_date')->get());
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
        return response()->json($semester);
    }

    public function update(Request $request, Semester $semester): JsonResponse
    {
        $semester->update($this->validated($request, $semester));
        if ($semester->is_current) {
            $semester->makeCurrent();
        }

        return response()->json($semester);
    }

    public function destroy(Semester $semester): JsonResponse
    {
        return $this->deleteOrExplain($semester, 'semester');
    }

    private function validated(Request $request, ?Semester $semester = null): array
    {
        $req = $semester ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:40'],
            'start_date' => [$req, 'date'],
            'end_date' => [$req, 'date', 'after:start_date'],
            'registration_open' => ['sometimes', 'boolean'],
            'is_current' => ['sometimes', 'boolean'],
        ]);
    }
}

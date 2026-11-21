<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Department::with('head:id,name')->withCount(['programs', 'facultyMembers'])->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Department::create($this->validated($request)), 201);
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json($department->load(['head:id,name', 'programs']));
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $department->update($this->validated($request, $department));

        return response()->json($department);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => [$department ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [$department ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('departments')->ignore($department)],
            'head_id' => ['nullable', 'exists:users,id'],
        ]);
    }
}

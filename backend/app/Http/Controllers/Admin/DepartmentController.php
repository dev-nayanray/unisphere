<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        return $this->listing(
            $request,
            Department::with('head:id,name')->withCount(['programs', 'facultyMembers']),
            searchable: ['name', 'code'],
            sortable: ['name', 'code', 'created_at'],
            defaultSort: 'name',
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

        return response()->json($department->load('head:id,name'));
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->loadCount(['programs', 'facultyMembers']);

        if ($department->programs_count || $department->faculty_members_count) {
            return response()->json([
                'message' => "{$department->code} still has {$department->programs_count} program(s) and "
                    ."{$department->faculty_members_count} faculty member(s). Move or delete them first.",
            ], 422);
        }

        return $this->deleteOrExplain($department, 'department');
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => [$department ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [$department ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('departments')->ignore($department)],
            // The head must be a faculty member (teacher or department head).
            'head_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', Role::faculty())],
        ], [
            'head_id.exists' => 'The department head must be a teacher or department head account.',
        ]);
    }
}

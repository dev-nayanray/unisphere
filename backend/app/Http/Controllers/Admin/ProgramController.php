<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        return $this->listing(
            $request,
            Program::with('department:id,code,name')
                ->withCount(['courses', 'students'])
                ->when($request->department_id, fn ($q, $id) => $q->where('department_id', $id)),
            searchable: ['name', 'code'],
            sortable: ['name', 'code', 'total_credits'],
            defaultSort: 'name',
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Program::create($this->validated($request)), 201);
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json($program->load(['department', 'courses']));
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $program->update($this->validated($request, $program));

        return response()->json($program->load('department:id,code,name'));
    }

    public function destroy(Program $program): JsonResponse
    {
        $program->loadCount(['courses', 'students']);

        if ($program->students_count || $program->courses_count) {
            return response()->json([
                'message' => "{$program->code} still has {$program->students_count} student(s) and "
                    ."{$program->courses_count} course(s). Move or delete them first.",
            ], 422);
        }

        return $this->deleteOrExplain($program, 'program');
    }

    private function validated(Request $request, ?Program $program = null): array
    {
        $req = $program ? 'sometimes' : 'required';

        return $request->validate([
            'department_id' => [$req, 'exists:departments,id'],
            'name' => [$req, 'string', 'max:120'],
            'code' => [$req, 'string', 'max:20', Rule::unique('programs')->ignore($program)],
            'total_credits' => ['sometimes', 'integer', 'min:1', 'max:300'],
        ]);
    }
}

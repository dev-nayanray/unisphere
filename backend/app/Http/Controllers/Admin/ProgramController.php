<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Program::with('department:id,code,name')
                ->withCount(['courses', 'students'])
                ->when($request->department_id, fn ($q, $id) => $q->where('department_id', $id))
                ->orderBy('name')
                ->get()
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

        return response()->json($program);
    }

    public function destroy(Program $program): JsonResponse
    {
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

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['student.program:id,code,name', 'faculty.department:id,code,name'])
            ->when($request->role, fn ($q, $role) => $q->where('role', $role))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'password' => $data['password'] ?? 'password',
            ]);
            $this->syncProfile($user, $data);

            return $user;
        });

        return response()->json($user->load(['student', 'faculty']), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['student.program', 'faculty.department']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);

        DB::transaction(function () use ($user, $data) {
            $user->update(collect($data)->only(['name', 'email', 'phone', 'role', 'is_active', 'password'])
                ->filter(fn ($v, $k) => $k !== 'password' || filled($v))
                ->all());
            $this->syncProfile($user, $data);
        });

        return response()->json($user->fresh(['student', 'faculty']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'sometimes' : 'required', 'email', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => [$user ? 'sometimes' : 'required', new Enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
            // Student profile
            'program_id' => ['required_if:role,student', 'nullable', 'exists:programs,id'],
            'student_id' => ['required_if:role,student', 'nullable', 'string', 'max:30',
                Rule::unique('students', 'student_id')->ignore($user?->student)],
            'batch' => ['nullable', 'string', 'max:20'],
            // Faculty profile
            'department_id' => ['nullable', 'exists:departments,id'],
            'employee_id' => ['nullable', 'string', 'max:30',
                Rule::unique('faculty_members', 'employee_id')->ignore($user?->faculty)],
            'designation' => ['nullable', 'string', 'max:60'],
        ]);
    }

    private function syncProfile(User $user, array $data): void
    {
        if ($user->role === Role::Student && isset($data['program_id'], $data['student_id'])) {
            $user->student()->updateOrCreate([], [
                'program_id' => $data['program_id'],
                'student_id' => $data['student_id'],
                'batch' => $data['batch'] ?? now()->format('Y'),
            ]);
        }

        if (in_array($user->role->value, Role::faculty(), true) && isset($data['department_id'], $data['employee_id'])) {
            $user->faculty()->updateOrCreate([], [
                'department_id' => $data['department_id'],
                'employee_id' => $data['employee_id'],
                'designation' => $data['designation'] ?? 'Lecturer',
            ]);
        }
    }
}

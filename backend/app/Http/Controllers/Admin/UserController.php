<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserController extends Controller
{
    use ListsRecords;

    public function index(Request $request): JsonResponse
    {
        // The user table is always paginated (it can hold thousands of students).
        $request->mergeIfMissing(['page' => 1]);

        return $this->listing(
            $request,
            User::query()
                ->with(['student.program:id,code,name', 'student.advisor:id,name', 'faculty.department:id,code,name'])
                ->when($request->role, fn ($q, $roles) => $q->whereIn('role', explode(',', $roles)))
                ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active'))),
            searchable: ['name', 'email', 'phone'],
            sortable: ['name', 'email', 'role', 'created_at'],
            defaultSort: 'name',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $temporary = $data['password'] ?? Str::password(10, symbols: false);

        $user = DB::transaction(function () use ($data, $temporary) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'password' => $temporary,
                // Admin-created accounts get a temporary password the user must replace.
                'must_change_password' => true,
            ]);
            $this->syncProfile($user, $data);

            return $user;
        });

        // TODO (Prompt 22): email the temporary password. For now the admin passes it on.
        return response()->json([
            ...$user->load(['student', 'faculty'])->toArray(),
            'temporary_password' => $temporary,
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['student.program', 'student.advisor:id,name', 'faculty.department']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);
        $deactivating = $request->has('is_active') && ! $request->boolean('is_active');

        $changingRole = isset($data['role']) && $data['role'] !== $user->role->value;

        if ($user->is($request->user()) && ($deactivating || $changingRole)) {
            return response()->json(['message' => 'You cannot deactivate yourself or change your own role.'], 422);
        }

        DB::transaction(function () use ($user, $data, $deactivating) {
            $user->update(collect($data)->only(['name', 'email', 'phone', 'role', 'is_active'])->all());
            $this->syncProfile($user, $data);

            // A deactivated account is signed out everywhere.
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        return response()->json($user->fresh(['student.program:id,code,name', 'student.advisor:id,name', 'faculty.department:id,code,name']));
    }

    /** Issue a new temporary password; the user must change it at next sign-in. */
    public function resetPassword(User $user): JsonResponse
    {
        $temporary = Str::password(10, symbols: false);

        $user->update(['password' => $temporary, 'must_change_password' => true]);
        $user->tokens()->delete();

        return response()->json([
            'message' => "Temporary password set for {$user->name}. Share it securely; it is shown only once.",
            'temporary_password' => $temporary,
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $inUse = $user->registrations()->exists() || $user->teachingSections()->exists();
        if ($inUse) {
            return response()->json([
                'message' => "{$user->name} has academic records (registrations or classes). Deactivate the account instead of deleting it.",
            ], 422);
        }

        return $this->deleteOrExplain($user, 'user');
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
            'advisor_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', Role::faculty())],
            // Faculty profile
            'department_id' => ['nullable', 'exists:departments,id'],
            'employee_id' => ['nullable', 'string', 'max:30',
                Rule::unique('faculty_members', 'employee_id')->ignore($user?->faculty)],
            'designation' => ['nullable', 'string', 'max:60'],
        ], [
            'advisor_id.exists' => 'The advisor must be a teacher or department head.',
        ]);
    }

    /** Create or update the student / faculty profile that goes with the account. */
    private function syncProfile(User $user, array $data): void
    {
        if ($user->role === Role::Student) {
            $fields = collect($data)->only(['program_id', 'student_id', 'batch', 'advisor_id'])->all();

            if ($user->student) {
                $user->student->update($fields);
            } elseif (isset($fields['program_id'], $fields['student_id'])) {
                $user->student()->create([...$fields, 'batch' => $fields['batch'] ?? now()->format('Y')]);
            }
        }

        if (in_array($user->role->value, Role::faculty(), true)) {
            $fields = collect($data)->only(['department_id', 'employee_id', 'designation'])->all();

            if ($user->faculty) {
                $user->faculty->update($fields);
            } elseif (isset($fields['department_id'], $fields['employee_id'])) {
                $user->faculty()->create([...$fields, 'designation' => $fields['designation'] ?? 'Lecturer']);
            }
        }
    }
}

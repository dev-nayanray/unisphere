<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated.']);
        }

        $user->loginActivities()->create([
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $token = $user->createToken($data['device_name'] ?? 'web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /** Update own name and phone. Email and role are managed by the registrar. */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->user()->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
        ], [
            'phone.regex' => 'Enter a valid Bangladeshi mobile number, e.g. 01712345678.',
        ]));

        return response()->json($this->profile($request->user()));
    }

    public function updateAvatar(Request $request, AvatarService $avatars): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $avatars->store($request->user(), $request->file('avatar'));

        return response()->json($this->profile($request->user()->fresh()));
    }

    /** Change password; every other device is signed out. */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();
        $user->update([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;
        $user->tokens()->when($currentId, fn ($q, $id) => $q->where('id', '!=', $id))->delete();

        return response()->json([
            'message' => 'Password updated. You have been signed out on other devices.',
            'user' => $this->profile($user),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->loginActivities()->limit(5)->get(['id', 'ip_address', 'user_agent', 'created_at'])
        );
    }

    /** Always answers the same way so nobody can probe which emails exist. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email belongs to an account, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    private function profile(User $user): array
    {
        $user->load(['student.program.department', 'student.advisor:id,name,email', 'faculty.department']);

        return [
            ...$user->toArray(),
            'role_label' => $user->role->label(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }
}

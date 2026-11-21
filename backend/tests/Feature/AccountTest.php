<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_name_and_phone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/auth/profile', ['name' => 'Nayan Ray', 'phone' => '01712345678'])
            ->assertOk()
            ->assertJsonPath('name', 'Nayan Ray');

        $this->assertSame('01712345678', $user->fresh()->phone);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/auth/profile', ['phone' => '12345'])
            ->assertJsonValidationErrors('phone');
    }

    public function test_avatar_is_resized_and_stored(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/api/auth/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 800, 600)], ['Accept' => 'application/json'])
            ->assertOk();

        $path = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([256, 256], [$w, $h]);
    }

    public function test_password_change_revokes_other_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $web = $user->createToken('web')->plainTextToken;

        $this->withToken($web)
            ->putJson('/api/auth/password', [
                'current_password' => 'password',
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count()); // only the current device remains
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'nayan@test.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'nayan@test.com'])->assertOk();
        // Unknown emails get the same answer (no account enumeration).
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@test.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'nayan@test.com',
            'password' => 'Reset1234',
            'password_confirmation' => 'Reset1234',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'nayan@test.com', 'password' => 'Reset1234'])->assertOk();
    }

    public function test_temporary_password_must_be_changed_first(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->actingAs($user)->getJson('/api/auth/me')->assertOk();

        $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'MyOwn1234',
            'password_confirmation' => 'MyOwn1234',
        ])->assertOk();

        $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();
    }

    public function test_admin_created_users_must_change_password(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'registrar']))
            ->postJson('/api/admin/users', ['name' => 'New Teacher', 'email' => 'new@test.com', 'role' => 'teacher'])
            ->assertCreated();

        $this->assertTrue(User::where('email', 'new@test.com')->first()->must_change_password);
    }

    public function test_logins_are_recorded(): void
    {
        $user = User::factory()->create(['email' => 'log@test.com']);
        $this->postJson('/api/auth/login', ['email' => 'log@test.com', 'password' => 'password'])->assertOk();

        $this->actingAs($user)->getJson('/api/auth/activity')->assertOk()->assertJsonCount(1);
    }
}

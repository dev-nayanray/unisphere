<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_and_fetch_their_profile(): void
    {
        $user = User::factory()->create(['email' => 'student@test.com']);

        $token = $this->postJson('/api/auth/login', ['email' => 'student@test.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('role', 'student');
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'student@test.com']);

        $this->postJson('/api/auth/login', ['email' => 'student@test.com', 'password' => 'wrong'])
            ->assertUnprocessable();
    }

    public function test_students_cannot_open_admin_routes(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_registrar_can_list_users(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'registrar']))
            ->getJson('/api/admin/users')
            ->assertOk();
    }
}

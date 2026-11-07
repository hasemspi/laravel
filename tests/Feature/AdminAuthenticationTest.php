<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_login_and_access_admin_session(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('user.email', $admin->email)->assertJsonMissing(['password']);

        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_invalid_password_and_non_admin_accounts_are_rejected_generically(): void
    {
        $user = User::factory()->create([
            'email' => 'student@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The provided credentials are invalid.');

        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'The provided credentials are invalid.');
    }

    public function test_inactive_admin_cannot_login_and_unauthenticated_session_is_denied(): void
    {
        $admin = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'role' => 'admin',
            'status' => 'inactive',
        ]);

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertUnauthorized();

        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_login_validation_rejects_malformed_input(): void
    {
        $this->postJson('/api/admin/login', ['email' => 'not-an-email', 'password' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_admin_can_logout(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->withHeader('Origin', 'http://localhost:3000')->actingAs($admin)->postJson('/api/admin/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/admin/me')->assertUnauthorized();
    }
}

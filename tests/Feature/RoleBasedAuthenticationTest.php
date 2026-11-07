<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleBasedAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_login_routes_admin_to_protected_admin_data_and_logout_ends_session(): void
    {
        $admin = User::factory()->create([
            'email' => 'dashboard-admin@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonMissing(['password']);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.administrators', 1);

        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_student_and_trainer_sessions_are_role_scoped_and_cannot_access_admin_endpoints(): void
    {
        $student = User::factory()->create([
            'email' => 'student@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $student->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'student');

        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/student/me')->assertOk()->assertJsonPath('user.email', $student->email);
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/admin/me')->assertForbidden();

        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $trainer = User::factory()->create([
            'email' => 'trainer@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'trainer',
            'status' => 'active',
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $trainer->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'trainer');

        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/trainer/me')->assertOk();
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/student/me')->assertForbidden();
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/admin/me')->assertForbidden();
    }

    public function test_registration_creates_pending_student_and_pending_account_cannot_login(): void
    {
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/register', [
                'name' => 'New Student',
                'email' => 'new.student@example.com',
                'password' => 'correct-password',
                'password_confirmation' => 'correct-password',
                'role' => 'admin',
            ])
            ->assertCreated()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonMissing(['password']);

        $student = User::query()->where('email', 'new.student@example.com')->firstOrFail();
        $this->assertSame('student', $student->role);
        $this->assertSame('pending', $student->status);
        $this->assertTrue(Hash::check('correct-password', $student->password));

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/login', ['email' => $student->email, 'password' => 'correct-password'])
            ->assertUnauthorized();
    }

    public function test_admin_can_approve_pending_student_through_user_management_api(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'pending']);

        $this->withHeader('Origin', 'http://localhost:3000')->actingAs($admin)
            ->patchJson('/api/admin/users/'.$student->id, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonMissing(['password']);

        $this->assertDatabaseHas('users', ['id' => $student->id, 'role' => 'student', 'status' => 'active']);
    }

    public function test_last_active_administrator_cannot_be_deleted_or_deactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->withHeader('Origin', 'http://localhost:3000')->actingAs($admin)
            ->patchJson('/api/admin/users/'.$admin->id, ['status' => 'inactive'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
    }

    public function test_another_admin_can_be_deactivated_when_an_active_admin_remains(): void
    {
        $currentAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $secondAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->withHeader('Origin', 'http://localhost:3000')->actingAs($currentAdmin)
            ->patchJson('/api/admin/users/'.$secondAdmin->id, ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('user.status', 'inactive');
    }

    public function test_login_is_rate_limited_after_five_attempts_for_the_same_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'rate-limit@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'rate-limit@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_user_search_treats_sql_payload_as_search_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        User::factory()->create(['name' => 'Ordinary Student', 'role' => 'student', 'status' => 'active']);

        $this->actingAs($admin)
            ->getJson('/api/admin/users?search=%27%20OR%201%3D1%20--')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonMissing(['name' => 'Ordinary Student']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_student_can_update_their_profile_information(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active', 'name' => 'Full Name', 'nickname' => null, 'email' => 'old@example.com']);

        $this->actingAs($student)->postJson('/api/student/profile', [
            'nickname' => 'Display Name',
        ])->assertOk()->assertJsonPath('user.nickname', 'Display Name')->assertJsonPath('user.name', 'Full Name')->assertJsonPath('user.email', 'old@example.com');

        $this->assertDatabaseHas('users', ['id' => $student->id, 'name' => 'Full Name', 'nickname' => 'Display Name', 'email' => 'old@example.com']);
    }

    public function test_student_profile_update_validates_email_and_is_role_protected(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $this->actingAs($student)->postJson('/api/student/password', ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertOk();
        $this->actingAs($student->refresh())->postJson('/api/student/password', ['current_password' => 'wrong-password', 'password' => 'another-password-123', 'password_confirmation' => 'another-password-123'])->assertUnprocessable();
        $this->actingAs($trainer)->postJson('/api/student/profile', ['nickname' => 'Trainer'])->assertForbidden();
        $this->actingAs($trainer)->postJson('/api/student/password', ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertForbidden();
    }
}

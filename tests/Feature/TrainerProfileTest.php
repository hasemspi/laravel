<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainer_can_update_only_their_own_profile_and_edits_return_to_review(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $profile = TrainerProfile::query()->create(['user_id' => $trainer->id, 'is_verified' => true, 'verified_at' => now()]);

        $response = $this->actingAs($trainer)->postJson('/api/trainer/profile', [
            'name' => 'Updated Trainer',
            'professional_title' => 'Senior PHP Mentor',
            'bio' => 'Builds production Laravel systems.',
            'experience_years' => 8,
            'expertise' => ['Laravel', 'APIs'],
            'youtube_intro_url' => 'https://www.youtube.com/watch?v=trainer',
        ]);

        $response->assertOk()->assertJsonPath('trainer.name', 'Updated Trainer')->assertJsonPath('trainer.verification_status', 'pending_review');
        $this->assertDatabaseHas('trainer_profiles', ['id' => $profile->id, 'professional_title' => 'Senior PHP Mentor', 'is_verified' => false, 'verified_at' => null]);
    }

    public function test_non_trainer_cannot_read_or_update_trainer_profile_endpoint(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/trainer/profile')->assertForbidden();

        $this->actingAs($student)->postJson('/api/trainer/profile', ['name' => 'Not allowed'])->assertForbidden();
    }

    public function test_trainer_profile_rejects_non_youtube_introduction_urls(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $this->actingAs($trainer)->postJson('/api/trainer/profile', ['youtube_intro_url' => 'https://example.com/video'])->assertUnprocessable()->assertJsonValidationErrors(['youtube_intro_url']);
    }

    public function test_public_profile_marks_unverified_claims_as_unverified_and_admin_can_verify(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        TrainerProfile::query()->create(['user_id' => $trainer->id, 'professional_title' => 'Designer', 'bio' => 'Self-submitted bio.']);

        $this->getJson('/api/trainers/'.$trainer->id)->assertOk()->assertJsonPath('trainer.is_verified', false)->assertJsonPath('trainer.bio', 'Self-submitted bio.')->assertJsonMissing(['verified_by_id']);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $profile = $trainer->trainerProfile()->firstOrFail();
        $this->actingAs($admin)->patchJson('/api/admin/trainer-profiles/'.$profile->id.'/verification', ['is_verified' => true])->assertOk()->assertJsonPath('profile.is_verified', true);
        $this->assertDatabaseHas('trainer_profiles', ['id' => $profile->id, 'is_verified' => true, 'verified_by_id' => $admin->id]);
    }

    public function test_public_course_payload_keeps_all_assigned_trainers_and_their_verification_state(): void
    {
        $firstTrainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $secondTrainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        TrainerProfile::query()->create(['user_id' => $firstTrainer->id, 'professional_title' => 'Backend Mentor', 'is_verified' => true]);
        TrainerProfile::query()->create(['user_id' => $secondTrainer->id, 'professional_title' => 'Frontend Mentor', 'is_verified' => false]);
        $course = Course::query()->create(['title' => 'Full Stack Foundations', 'slug' => 'full-stack-foundations', 'short_description' => 'A practical course.', 'status' => 'published']);
        $course->trainers()->attach([$firstTrainer->id, $secondTrainer->id]);

        $response = $this->getJson('/api/courses/'.$course->slug)->assertOk();

        $response->assertJsonCount(2, 'course.trainers')->assertJsonPath('course.trainers.0.is_verified', true)->assertJsonPath('course.trainers.1.is_verified', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\StudentSuccessStory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentSuccessStoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_feed_only_returns_published_stories_and_filters_categories(): void
    {
        StudentSuccessStory::factory()->create(['category' => 'Freelancing', 'status' => 'published']);
        StudentSuccessStory::factory()->create(['category' => 'Job Placement', 'status' => 'published']);
        StudentSuccessStory::factory()->create(['category' => 'Freelancing', 'status' => 'draft']);

        $this->getJson('/api/success-stories?category=Freelancing')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'Freelancing');

        $draft = StudentSuccessStory::query()->where('status', 'draft')->firstOrFail();
        $this->getJson('/api/success-stories/'.$draft->id)->assertNotFound();
    }

    public function test_admin_can_create_youtube_story_and_non_admin_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/admin/success-stories')->assertForbidden();

        $this->actingAs($admin)->postJson('/api/admin/success-stories', [
            'student_name' => 'Sadia Akter',
            'designation' => 'Digital Marketing Executive',
            'category' => 'Job Placement',
            'description' => 'A published career success story.',
            'achievement_label' => 'Current Salary',
            'achievement_value' => '৳50,000+',
            'video_source' => 'youtube',
            'youtube_url' => 'https://www.youtube.com/watch?v=ysz5S6PUM-U',
            'social_links' => ['linkedin' => 'https://www.linkedin.com/in/example'],
            'status' => 'published',
            'is_featured' => true,
        ])->assertCreated()->assertJsonPath('story.video_type', 'youtube');

        $this->assertDatabaseHas('student_success_stories', ['student_name' => 'Sadia Akter', 'status' => 'published']);
    }

    public function test_admin_can_replace_uploaded_video_without_leaving_the_old_file(): void
    {
        if (! is_resource(@tmpfile())) {
            $this->markTestSkipped('The PHP test environment does not provide a writable temporary file stream.');
        }

        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $first = $this->actingAs($admin)->post('/api/admin/success-stories', [
            'student_name' => 'Rafiq Islam',
            'designation' => 'Web Developer',
            'category' => 'Freelancing',
            'description' => 'A video success story.',
            'video_source' => 'server',
            'video' => UploadedFile::fake()->create('first.mp4', 100, 'video/mp4'),
            'status' => 'draft',
        ], ['Accept' => 'application/json'])->assertCreated();
        $storyId = $first->json('story.id');
        $oldPath = StudentSuccessStory::findOrFail($storyId)->video_path;
        Storage::disk('public')->assertExists($oldPath);

        $second = UploadedFile::fake()->create('second.mp4', 100, 'video/mp4');
        $this->actingAs($admin)->post('/api/admin/success-stories/'.$storyId, [
            'student_name' => 'Rafiq Islam',
            'designation' => 'Web Developer',
            'category' => 'Freelancing',
            'description' => 'An updated video success story.',
            'video_source' => 'server',
            'video' => $second,
            'status' => 'published',
        ], ['Accept' => 'application/json'])->assertOk();

        $newPath = StudentSuccessStory::findOrFail($storyId)->video_path;
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCourseThumbnailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_a_public_course_thumbnail(): void
    {
        Storage::fake('frontend_public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Image Course', 'slug' => 'image-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'draft']);
        $imagePath = dirname(base_path()).'/frontend/public/images/course-ai.webp';

        $response = $this->actingAs($admin)->post('/api/admin/courses/'.$course->id.'/thumbnail', [
            'thumbnail' => new UploadedFile($imagePath, 'course-cover.png', 'image/png', null, true),
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('images/course-thumbnails/', (string) $response->json('course.thumbnail_path'));
        $path = $course->fresh()->thumbnail_path;
        Storage::disk('frontend_public')->assertExists($path);
    }
}

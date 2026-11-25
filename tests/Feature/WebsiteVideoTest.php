<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_only_the_active_youtube_configuration(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->post('/api/admin/website-video', [
            'video_type' => 'youtube',
            'youtube_url' => 'https://www.youtube.com/watch?v=HRIFyYg-5Dc',
        ])->assertOk();

        $this->getJson('/api/website-video')
            ->assertOk()
            ->assertExactJson([
                'video_type' => 'youtube',
                'youtube_video_id' => 'HRIFyYg-5Dc',
                'youtube_embed_url' => 'https://www.youtube.com/embed/HRIFyYg-5Dc?rel=0',
            ]);
    }

    public function test_only_an_active_admin_can_update_the_video(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)
            ->post('/api/admin/website-video', [
                'video_type' => 'youtube',
                'youtube_url' => 'https://youtu.be/HRIFyYg-5Dc',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_upload_mp4_and_replace_it_with_webm(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $firstResponse = $this->actingAs($admin)->post('/api/admin/website-video', [
            'video_type' => 'upload',
            'video' => $this->uploadedVideo('intro.mp4', 'video/mp4'),
        ]);

        $firstResponse->assertOk();
        $firstPath = $firstResponse->json('video.video_filename');
        $firstStoredPath = 'website/'.$firstPath;
        Storage::disk('public')->assertExists($firstStoredPath);

        $secondResponse = $this->actingAs($admin)->post('/api/admin/website-video', [
            'video_type' => 'upload',
            'video' => $this->uploadedVideo('intro.webm', 'video/webm'),
        ]);

        $secondResponse->assertOk();
        $secondPath = 'website/'.$secondResponse->json('video.video_filename');
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstStoredPath);
        $this->assertSame('upload', $this->getJson('/api/website-video')->json('video_type'));
    }

    private function uploadedVideo(string $filename, string $mimeType): UploadedFile
    {
        $testingDirectory = storage_path('framework/testing');
        if (!is_dir($testingDirectory)) mkdir($testingDirectory, 0775, true);
        $path = tempnam($testingDirectory, 'trustygen-video-');
        $content = $mimeType === 'video/mp4'
            ? "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2mp41"
            : "\x1A\x45\xDF\xA3\x93\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x88webm\x42\x87\x81\x02\x42\x85\x81\x02";
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mimeType, null, true);
    }
}

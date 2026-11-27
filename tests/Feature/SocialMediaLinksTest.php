<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialMediaLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_empty_links_until_an_admin_configures_them(): void
    {
        $this->getJson('/api/social-links')
            ->assertOk()
            ->assertExactJson([
                'links' => [
                    'facebook' => '',
                    'youtube' => '',
                    'linkedin' => '',
                    'instagram' => '',
                    'whatsapp' => '',
                ],
            ]);
    }

    public function test_admin_can_update_all_social_media_links_and_public_endpoint_uses_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $links = [
            'facebook_url' => 'https://www.facebook.com/trustygen',
            'youtube_url' => 'https://www.youtube.com/@trustygen',
            'linkedin_url' => 'https://www.linkedin.com/company/trustygen',
            'instagram_url' => 'https://www.instagram.com/trustygen',
            'whatsapp_url' => 'https://wa.me/8801644415771',
        ];

        $this->actingAs($admin)
            ->postJson('/api/admin/social-links', $links)
            ->assertOk()
            ->assertJsonPath('links.facebook', $links['facebook_url']);

        $this->getJson('/api/social-links')
            ->assertOk()
            ->assertJsonPath('links.youtube', $links['youtube_url'])
            ->assertJsonPath('links.whatsapp', $links['whatsapp_url']);
    }

    public function test_only_an_active_admin_can_update_social_media_links(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)
            ->postJson('/api/admin/social-links', ['facebook_url' => 'https://facebook.com/trustygen'])
            ->assertForbidden();
    }
}

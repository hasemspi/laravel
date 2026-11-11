<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBlogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_blog_posts_and_sidebar_widgets(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $category = BlogCategory::factory()->create();

        $post = $this->actingAs($admin)->postJson('/api/admin/blog/posts', [
            'blog_category_id' => $category->id,
            'title' => 'A published TrustyGen guide',
            'content' => 'Published content',
            'status' => 'published',
            'is_featured' => true,
        ]);
        $post->assertCreated()->assertJsonPath('post.slug', 'a-published-trustygen-guide');

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'A published TrustyGen guide',
            'status' => 'published',
            'is_featured' => true,
        ]);

        $widget = $this->actingAs($admin)->postJson('/api/admin/blog/widgets', [
            'key' => 'updates',
            'title' => 'TrustyGen Updates',
            'body' => 'New articles are published here.',
            'type' => 'updates',
            'is_enabled' => true,
        ]);
        $widget->assertCreated()->assertJsonPath('widget.key', 'updates');

    }

    public function test_non_admin_cannot_access_blog_management(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/admin/blog/posts')->assertForbidden();
    }
}

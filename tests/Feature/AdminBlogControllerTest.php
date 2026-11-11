<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogTag;
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

    public function test_admin_can_manage_tags_scheduled_posts_and_widget_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $parent = BlogCategory::factory()->create();
        $child = $this->actingAs($admin)->postJson('/api/admin/blog/categories', [
            'name' => 'Child category',
            'parent_id' => $parent->id,
        ])->assertCreated()->json('category');
        $tag = $this->actingAs($admin)->postJson('/api/admin/blog/tags', [
            'name' => 'Lead Generation',
        ])->assertCreated()->json('tag');

        $post = $this->actingAs($admin)->postJson('/api/admin/blog/posts', [
            'blog_category_id' => $child['id'],
            'title' => 'Scheduled guide',
            'content' => '<p>Scheduled content</p>',
            'status' => 'scheduled',
            'published_at' => now()->addDay()->toIso8601String(),
            'tags' => [$tag['id']],
            'seo_title' => 'Scheduled guide SEO title',
        ]);

        $post->assertCreated()->assertJsonPath('post.status', 'scheduled')->assertJsonPath('post.tags.0.id', $tag['id']);

        $firstWidget = $this->actingAs($admin)->postJson('/api/admin/blog/widgets', [
            'key' => 'search', 'title' => 'Search', 'type' => 'search', 'is_enabled' => true,
        ])->assertCreated()->json('widget');
        $secondWidget = $this->actingAs($admin)->postJson('/api/admin/blog/widgets', [
            'key' => 'newsletter', 'title' => 'Newsletter', 'type' => 'newsletter', 'is_enabled' => true,
        ])->assertCreated()->json('widget');

        $this->actingAs($admin)->postJson('/api/admin/blog/widgets/reorder', [
            'items' => [
                ['id' => $secondWidget['id'], 'sort_order' => 0],
                ['id' => $firstWidget['id'], 'sort_order' => 1],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('blog_widgets', ['id' => $secondWidget['id'], 'sort_order' => 0]);
        $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->json('post.id'), 'blog_tag_id' => $tag['id']]);
        $this->assertInstanceOf(BlogTag::class, BlogTag::find($tag['id']));
    }

    public function test_admin_can_moderate_blog_comments(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $comment = BlogComment::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/blog/comments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment->id);

        $this->actingAs($admin)->patchJson('/api/admin/blog/comments/'.$comment->id, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('comment.status', 'approved');

        $this->assertDatabaseHas('blog_comments', ['id' => $comment->id, 'status' => 'approved']);
    }
}

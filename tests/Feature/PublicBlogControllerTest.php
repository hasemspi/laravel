<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicBlogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_index_returns_published_posts_in_six_item_pages_with_search_and_category_filters(): void
    {
        $category = BlogCategory::factory()->create(['name' => 'Web Development', 'slug' => 'web-development']);
        $otherCategory = BlogCategory::factory()->create(['name' => 'Career', 'slug' => 'career']);

        BlogPost::factory()->count(7)->for($category, 'category')->create([
            'title' => 'Laravel search article',
        ]);
        BlogPost::factory()->for($otherCategory, 'category')->create(['title' => 'Laravel career article']);
        BlogPost::factory()->draft()->for($category, 'category')->create(['title' => 'Laravel draft article']);

        $response = $this->getJson('/api/blog?category=web-development&search=Laravel&page=2');

        $response->assertOk()->assertJsonPath('per_page', 6)->assertJsonPath('current_page', 2)->assertJsonPath('total', 7);
        $this->assertCount(1, $response->json('data'));
        $webCategory = collect($response->json('categories'))->firstWhere('slug', 'web-development');
        $this->assertSame('Web Development', $webCategory['name']);
        $this->assertSame(7, $webCategory['posts_count']);
    }

    public function test_blog_detail_returns_not_found_for_unpublished_posts(): void
    {
        $post = BlogPost::factory()->draft()->create();

        $this->getJson('/api/blog/'.$post->slug)->assertNotFound();
    }

    public function test_scheduled_posts_are_available_as_single_posts_after_the_publish_time(): void
    {
        $post = BlogPost::factory()->create([
            'status' => 'scheduled',
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/blog/'.$post->slug)
            ->assertOk()
            ->assertJsonPath('post.slug', $post->slug);
    }

    public function test_visitors_can_submit_comments_for_published_posts(): void
    {
        $post = BlogPost::factory()->create(['status' => 'published', 'published_at' => now()]);

        $this->postJson('/api/blog/'.$post->slug.'/comments', [
            'name' => 'Reader One',
            'email' => 'reader@example.com',
            'body' => 'Helpful article.',
        ])->assertCreated()->assertJsonPath('message', 'Your comment was submitted for moderation.');

        $this->assertDatabaseHas('blog_comments', [
            'blog_post_id' => $post->id,
            'name' => 'Reader One',
            'status' => 'pending',
        ]);
    }
}

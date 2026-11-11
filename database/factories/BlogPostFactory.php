<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'blog_category_id' => BlogCategory::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(100, 999),
            'excerpt' => fake()->paragraph(),
            'content' => fake()->paragraphs(3, true),
            'image_path' => null,
            'image_alt' => $title,
            'author_name' => 'TrustyGen Team',
            'status' => 'published',
            'is_featured' => false,
            'published_at' => now(),
            'read_time_minutes' => fake()->numberBetween(4, 12),
            'views' => fake()->numberBetween(0, 1000),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'published_at' => null]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }
}

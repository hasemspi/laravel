<?php

namespace Database\Factories;

use App\Models\BlogWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlogWidget>
 */
class BlogWidgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'type' => 'custom',
            'is_enabled' => true,
            'sort_order' => 0,
        ];
    }
}

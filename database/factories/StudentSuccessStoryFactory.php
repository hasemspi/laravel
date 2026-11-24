<?php

namespace Database\Factories;

use App\Models\StudentSuccessStory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentSuccessStory>
 */
class StudentSuccessStoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_name' => fake()->name(),
            'designation' => fake()->jobTitle(),
            'category' => fake()->randomElement(['Freelancing', 'Job Placement', 'Entrepreneur', 'Video Stories']),
            'description' => fake()->sentence(12),
            'achievement_label' => 'Monthly Earning',
            'achievement_value' => '$1,200+',
            'video_type' => 'youtube',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'social_links' => ['linkedin' => 'https://www.linkedin.com/'],
            'video_duration' => '05:24',
            'display_order' => 0,
            'is_featured' => false,
            'status' => 'published',
        ];
    }
}

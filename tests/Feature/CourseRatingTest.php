<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseRating;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_course_payload_contains_dynamic_rating_metrics_and_temporary_student_count(): void
    {
        $course = Course::query()->create(['title' => 'Rated Course', 'slug' => 'rated-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $first = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $second = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Enrollment::query()->create(['user_id' => $first->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        Enrollment::query()->create(['user_id' => $second->id, 'course_id' => $course->id, 'status' => 'completed', 'enrolled_at' => now()]);
        CourseRating::query()->create(['course_id' => $course->id, 'user_id' => $first->id, 'rating' => 5, 'review' => 'Excellent course.', 'approval_status' => 'approved']);
        CourseRating::query()->create(['course_id' => $course->id, 'user_id' => $second->id, 'rating' => 4, 'review' => 'Very useful lessons.', 'approval_status' => 'approved']);

        $this->getJson('/api/courses/'.$course->slug)
            ->assertOk()
            ->assertJsonPath('course.average_rating', 4.5)
            ->assertJsonPath('course.total_reviews', 2)
            ->assertJsonPath('course.total_students', 2);

        $this->getJson('/api/courses?per_page=12')
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', 4.5)
            ->assertJsonPath('data.0.total_reviews', 2)
            ->assertJsonPath('data.0.total_students', 2);
    }

    public function test_only_verified_enrolled_students_can_create_one_rating_per_course(): void
    {
        $course = Course::query()->create(['title' => 'Reviewable Course', 'slug' => 'reviewable-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $outsider = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($outsider)->postJson('/api/student/courses/'.$course->id.'/rating', ['rating' => 5])->assertForbidden();

        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        $this->actingAs($student)->postJson('/api/student/courses/'.$course->id.'/rating', ['rating' => 5, 'review' => 'Excellent'])->assertCreated();
        $this->actingAs($student)->postJson('/api/student/courses/'.$course->id.'/rating', ['rating' => 4, 'review' => 'Updated'])->assertOk();

        $this->assertSame(1, CourseRating::query()->where('course_id', $course->id)->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('course_ratings', ['course_id' => $course->id, 'user_id' => $student->id, 'rating' => 4, 'review' => 'Updated', 'approval_status' => 'pending']);
    }

    public function test_manual_demo_student_count_overrides_verified_count_for_public_display(): void
    {
        $course = Course::query()->create(['title' => 'Demo Count Course', 'slug' => 'demo-count-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published', 'display_student_count' => 890, 'display_rating' => 4.8, 'display_review_count' => 124]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);

        $this->getJson('/api/courses/'.$course->slug)->assertOk()->assertJsonPath('course.total_students', 890)->assertJsonPath('course.average_rating', 0)->assertJsonPath('course.total_reviews', 0);
    }
}

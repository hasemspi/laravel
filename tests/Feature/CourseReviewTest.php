<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseRating;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_reviews_are_approved_positive_verified_reviews_paginated_by_ten(): void
    {
        $course = Course::query()->create(['title' => 'Review Course', 'slug' => 'review-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $students = User::factory()->count(12)->create(['role' => 'student', 'status' => 'active']);

        foreach ($students as $index => $student) {
            Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => $index === 11 ? 'completed' : 'active', 'enrolled_at' => now()]);
            CourseRating::query()->create(['course_id' => $course->id, 'user_id' => $student->id, 'rating' => $index % 2 === 0 ? 5 : 4, 'review' => 'Verified learning experience '.$index, 'approval_status' => 'approved']);
        }

        $pendingStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Enrollment::query()->create(['user_id' => $pendingStudent->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        CourseRating::query()->create(['course_id' => $course->id, 'user_id' => $pendingStudent->id, 'rating' => 5, 'review' => 'Pending review', 'approval_status' => 'pending']);
        $unverifiedStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        CourseRating::query()->create(['course_id' => $course->id, 'user_id' => $unverifiedStudent->id, 'rating' => 5, 'review' => 'Unverified review', 'approval_status' => 'approved']);

        $this->getJson('/api/courses/'.$course->slug.'/reviews?per_page=10&page=1')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total_reviews', 12)
            ->assertJsonPath('last_page', 2)
            ->assertJsonMissing(['review' => 'Pending review']);

        $this->getJson('/api/courses/'.$course->slug.'/reviews?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('next_page_url', null);
    }

    public function test_only_verified_students_can_submit_and_admin_can_approve_positive_reviews(): void
    {
        $course = Course::query()->create(['title' => 'Moderated Course', 'slug' => 'moderated-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($student)->postJson('/api/student/courses/'.$course->id.'/rating', ['rating' => 5, 'review' => 'Great course'])->assertForbidden();

        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        $response = $this->actingAs($student)->postJson('/api/student/courses/'.$course->id.'/rating', ['rating' => 5, 'review' => 'Great course'])
            ->assertCreated()
            ->assertJsonPath('rating.approval_status', 'pending');
        $ratingId = $response->json('rating.id');

        $this->getJson('/api/courses/'.$course->slug.'/reviews')->assertOk()->assertJsonPath('total_reviews', 0)->assertJsonPath('average_rating', 0);

        $this->actingAs($admin)->patchJson('/api/admin/course-ratings/'.$ratingId, ['approval_status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('rating.approval_status', 'approved');

        $this->getJson('/api/courses/'.$course->slug.'/reviews')->assertOk()->assertJsonPath('data.0.review', 'Great course')->assertJsonPath('total_reviews', 1)->assertJsonPath('average_rating', 5);
        $this->getJson('/api/courses/'.$course->slug)->assertOk()->assertJsonPath('course.total_reviews', 1)->assertJsonPath('course.average_rating', 5);
    }
}

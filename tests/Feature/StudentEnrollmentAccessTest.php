<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentEnrollmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_status_distinguishes_unenrolled_pending_and_approved_students(): void
    {
        $course = $this->course('Access course');
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/student/courses/'.$course->id.'/access')
            ->assertOk()->assertJsonPath('status', 'not_enrolled')->assertJsonPath('can_enroll', true);

        Payment::query()->create([
            'user_id' => $student->id, 'course_id' => $course->id, 'student_name' => $student->name,
            'student_email' => $student->email, 'student_mobile' => '01700000000', 'amount' => 900,
            'transaction_id' => 'TXN-ACCESS-PENDING', 'status' => 'pending',
        ]);
        $this->actingAs($student)->getJson('/api/student/courses/'.$course->id.'/access')
            ->assertOk()->assertJsonPath('status', 'payment_pending')->assertJsonPath('can_enroll', false);

        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        $this->actingAs($student)->getJson('/api/student/courses/'.$course->id.'/access')
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('has_access', true);
    }

    public function test_checkout_rejects_a_second_payment_for_an_existing_pending_or_approved_course(): void
    {
        $course = $this->course('Duplicate checkout course');
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Payment::query()->create([
            'user_id' => $student->id, 'course_id' => $course->id, 'student_name' => $student->name,
            'student_email' => $student->email, 'student_mobile' => '01800000000', 'amount' => 900,
            'transaction_id' => 'TXN-DUPLICATE-PENDING', 'status' => 'pending',
        ]);

        $this->actingAs($student)->postJson('/api/courses/'.$course->slug.'/checkout', [
            'name' => $student->name, 'email' => $student->email, 'mobile' => '01800000000', 'transaction_id' => 'TXN-DUPLICATE-SECOND',
        ])->assertUnprocessable()->assertJsonPath('message', 'A payment for this course is already pending verification.');
        $this->assertSame(1, Payment::query()->where('user_id', $student->id)->where('course_id', $course->id)->count());

        Payment::query()->where('transaction_id', 'TXN-DUPLICATE-PENDING')->update(['status' => 'verified']);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        $this->actingAs($student)->postJson('/api/courses/'.$course->slug.'/checkout', [
            'name' => $student->name, 'email' => $student->email, 'mobile' => '01800000000', 'transaction_id' => 'TXN-DUPLICATE-THIRD',
        ])->assertUnprocessable()->assertJsonPath('message', 'You already have access to this course.');
    }

    public function test_completed_course_is_returned_with_completed_progress_and_lesson_access_remains_protected(): void
    {
        $course = $this->course('Completion course');
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Module One', 'sort_order' => 1]);
        $lesson = Lesson::query()->create(['course_module_id' => $module->id, 'title' => 'Lesson One', 'duration_seconds' => 60, 'unlock_type' => 'immediate', 'is_published' => true, 'sort_order' => 1]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);

        $this->actingAs($student)->postJson('/api/student/lessons/'.$lesson->id.'/progress', [
            'watched_seconds' => 60, 'video_duration_seconds' => 60, 'progress_percent' => 100, 'is_completed' => true,
        ])->assertOk()->assertJsonPath('enrollment.status', 'completed');

        $this->actingAs($student)->getJson('/api/student/courses/'.$course->id.'/access')
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->actingAs($student)->getJson('/api/student/dashboard')
            ->assertOk()->assertJsonPath('courses.0.status', 'completed')->assertJsonPath('courses.0.progress.percent', 100);
        $this->assertDatabaseHas('lesson_progress', ['user_id' => $student->id, 'lesson_id' => $lesson->id, 'is_completed' => true]);
    }

    public function test_student_without_an_enrollment_cannot_read_course_lessons(): void
    {
        $course = $this->course('Protected course');
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Module One', 'sort_order' => 1]);
        $lesson = Lesson::query()->create(['course_module_id' => $module->id, 'title' => 'Lesson One', 'unlock_type' => 'immediate', 'is_published' => true, 'sort_order' => 1]);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/student/lessons/'.$lesson->id)->assertForbidden();
        $this->actingAs($student)->getJson('/api/student/courses/'.$course->id)->assertForbidden();
    }

    private function course(string $title): Course
    {
        return Course::query()->create([
            'title' => $title, 'slug' => str($title)->slug(), 'short_description' => 'Course description',
            'price' => 900, 'status' => 'published',
        ]);
    }
}

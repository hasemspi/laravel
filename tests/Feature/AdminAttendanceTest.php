<?php

namespace Tests\Feature;

use App\Models\BatchLesson;
use App\Models\BatchLessonProgress;
use App\Models\BatchModule;
use App\Models\Course;
use App\Models\CourseBatch;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\EnrollmentBatchAccess;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_learning_progress_and_activity_based_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Progress Course', 'slug' => 'progress-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Module 1', 'sort_order' => 1]);
        $firstLesson = Lesson::query()->create(['course_module_id' => $module->id, 'title' => 'Lesson 1', 'duration_seconds' => 120, 'sort_order' => 1, 'is_published' => true]);
        $secondLesson = Lesson::query()->create(['course_module_id' => $module->id, 'title' => 'Lesson 2', 'duration_seconds' => 180, 'sort_order' => 2, 'is_published' => true]);
        $enrollment = Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        LessonProgress::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'course_module_id' => $module->id, 'lesson_id' => $firstLesson->id, 'watched_seconds' => 120, 'video_duration_seconds' => 120, 'progress_percent' => 100, 'is_completed' => true, 'last_watched_at' => now()]);
        LessonProgress::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'course_module_id' => $module->id, 'lesson_id' => $secondLesson->id, 'watched_seconds' => 60, 'video_duration_seconds' => 180, 'progress_percent' => 33, 'is_completed' => false, 'last_watched_at' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance');

        $response->assertOk()->assertJsonPath('data.0.student.email', $student->email)->assertJsonPath('data.0.learning_progress_percent', 50)->assertJsonPath('data.0.attendance_percent', 100)->assertJsonPath('data.0.video_watch_time_minutes', 3)->assertJsonPath('data.0.total_lessons', 2);

        $this->actingAs($admin)->getJson('/api/admin/attendance/'.$enrollment->id)->assertOk()->assertJsonPath('summary.course_completion_percent', 50)->assertJsonPath('modules.0.lessons.0.is_completed', true)->assertJsonPath('modules.0.lessons.1.progress_percent', 33);
    }

    public function test_batch_attendance_uses_batch_learning_activity_and_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Batch Progress Course', 'slug' => 'batch-progress-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $batch = CourseBatch::query()->create(['course_id' => $course->id, 'batch_number' => 7, 'status' => 'published']);
        $module = BatchModule::query()->create(['course_batch_id' => $batch->id, 'title' => 'Batch Module', 'sort_order' => 1]);
        $lesson = BatchLesson::query()->create(['batch_module_id' => $module->id, 'title' => 'Batch Lesson', 'duration_seconds' => 120, 'sort_order' => 1, 'is_published' => true]);
        $enrollment = Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        EnrollmentBatchAccess::query()->create(['enrollment_id' => $enrollment->id, 'course_batch_id' => $batch->id, 'access_source' => 'manual']);
        BatchLessonProgress::query()->create(['user_id' => $student->id, 'enrollment_id' => $enrollment->id, 'course_batch_id' => $batch->id, 'batch_lesson_id' => $lesson->id, 'watched_seconds' => 120, 'video_duration_seconds' => 120, 'progress_percent' => 100, 'is_completed' => true, 'last_watched_at' => now()]);

        $this->actingAs($admin)->getJson('/api/admin/attendance?batch_id='.$batch->id)->assertOk()->assertJsonPath('data.0.batch.batch_number', 7)->assertJsonPath('data.0.attendance_percent', 100)->assertJsonPath('data.0.learning_progress_percent', 100);
    }

    public function test_attendance_endpoint_requires_an_active_admin(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/admin/attendance')->assertForbidden();
    }
}

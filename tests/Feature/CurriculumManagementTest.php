<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_build_approve_publish_and_reorder_curriculum(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Curriculum Course', 'slug' => 'curriculum-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'draft']);

        $module = $this->actingAs($admin)->postJson('/api/admin/courses/'.$course->id.'/modules', ['title' => 'Module 1'])->assertCreated()->json('module');
        $lesson = $this->actingAs($admin)->postJson('/api/admin/modules/'.$module['id'].'/lessons', ['title' => 'Lesson 1', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'duration_seconds' => 60])->assertCreated()->json('lesson');

        $this->assertDatabaseHas('lessons', ['id' => $lesson['id'], 'video_reference' => 'dQw4w9WgXcQ', 'approval_status' => 'approved', 'is_published' => 0]);
        $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson['id'].'/publish')->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson['id'].'/unpublish')->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson['id'].'/approve')->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/lessons/'.$lesson['id'].'/publish')->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/modules/'.$module['id'].'/reorder', ['sort_order' => 1])->assertOk();
        $this->assertDatabaseHas('lessons', ['id' => $lesson['id'], 'is_published' => 1]);
    }

    public function test_trainer_is_limited_to_assigned_courses_and_must_submit_lessons(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $assigned = Course::query()->create(['title' => 'Assigned', 'slug' => 'assigned', 'short_description' => 'Course', 'price' => 100, 'status' => 'draft']);
        $other = Course::query()->create(['title' => 'Other', 'slug' => 'other', 'short_description' => 'Course', 'price' => 100, 'status' => 'draft']);
        $assigned->trainers()->attach($trainer);

        $this->actingAs($trainer)->getJson('/api/trainer/courses')->assertOk()->assertJsonPath('courses.0.id', $assigned->id);
        $this->actingAs($trainer)->getJson('/api/trainer/courses/'.$other->id.'/curriculum')->assertForbidden();
        $module = $this->actingAs($trainer)->postJson('/api/trainer/courses/'.$assigned->id.'/modules', ['title' => 'Trainer module'])->assertCreated()->json('module');
        $lesson = $this->actingAs($trainer)->postJson('/api/trainer/modules/'.$module['id'].'/lessons', ['title' => 'Trainer lesson', 'video_id' => 'dQw4w9WgXcQ'])->assertCreated()->json('lesson');
        $this->assertSame('draft', $lesson['approval_status']);
        $this->actingAs($trainer)->postJson('/api/trainer/lessons/'.$lesson['id'].'/submit')->assertOk()->assertJsonPath('lesson.approval_status', 'pending');
        $this->actingAs($trainer)->postJson('/api/admin/lessons/'.$lesson['id'].'/approve')->assertForbidden();
    }

    public function test_active_student_can_update_progress_but_cannot_access_another_course(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Learning', 'slug' => 'learning', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Module', 'sort_order' => 1]);
        $lesson = Lesson::query()->create(['course_module_id' => $module->id, 'title' => 'Lesson', 'video_reference' => 'dQw4w9WgXcQ', 'duration_seconds' => 60, 'unlock_type' => 'sequential', 'is_published' => true, 'approval_status' => 'approved', 'sort_order' => 1]);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);

        $this->actingAs($student)->postJson('/api/student/lessons/'.$lesson->id.'/progress', ['watched_seconds' => 60, 'video_duration_seconds' => 60, 'is_completed' => true])->assertOk();
        $this->assertDatabaseHas('lesson_progress', ['user_id' => $student->id, 'lesson_id' => $lesson->id, 'is_completed' => 1]);
        $this->actingAs(User::factory()->create(['role' => 'student', 'status' => 'active']))->getJson('/api/student/lessons/'.$lesson->id)->assertForbidden();
    }
}

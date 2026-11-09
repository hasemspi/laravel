<?php

namespace Tests\Feature;

use App\Models\BatchLesson;
use App\Models\BatchLessonProgress;
use App\Models\BatchModule;
use App\Models\Course;
use App\Models\CourseAccessRule;
use App\Models\CourseBatch;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BatchVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_batch_curriculum_is_immutable_and_legacy_curriculum_is_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Versioned Course', 'slug' => 'versioned-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $legacyModule = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Legacy module', 'sort_order' => 1]);

        $batch = $this->actingAs($admin)->postJson('/api/admin/courses/'.$course->id.'/batches', [
            'batch_number' => 401,
            'status' => 'draft',
            'release_date' => now()->subDay()->toISOString(),
            'trainer_ids' => [$trainer->id],
        ])->assertCreated()->json('batch');

        $module = $this->actingAs($admin)->postJson('/api/admin/batches/'.$batch['id'].'/modules', ['title' => 'Batch 401 module'])->assertCreated()->json('module');
        $lesson = $this->actingAs($admin)->postJson('/api/admin/batch-modules/'.$module['id'].'/lessons', ['title' => 'Batch 401 lesson', 'video_id' => 'dQw4w9WgXcQ', 'unlock_type' => 'immediate'])->assertCreated()->json('lesson');
        $this->actingAs($admin)->postJson('/api/admin/batch-lessons/'.$lesson['id'].'/publish')->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/batches/'.$batch['id'], ['status' => 'published'])->assertOk();

        $this->actingAs($admin)->putJson('/api/admin/batch-modules/'.$module['id'], ['title' => 'Must not change'])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/admin/batches/'.$batch['id'], ['status' => 'draft'])->assertUnprocessable();
        $this->assertDatabaseHas('course_modules', ['id' => $legacyModule->id, 'title' => 'Legacy module']);
        $this->assertDatabaseHas('batch_modules', ['id' => $module['id'], 'title' => 'Batch 401 module']);
    }

    public function test_lifetime_access_releases_future_batches_without_overwriting_old_progress(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Lifetime Course', 'slug' => 'lifetime-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $enrollment = Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()->subDays(5)]);
        CourseAccessRule::query()->create(['course_id' => $course->id, 'lifetime_access_enabled' => true, 'future_batch_access' => true, 'release_rule' => 'published_after_enrollment']);

        $batch401 = CourseBatch::query()->create(['course_id' => $course->id, 'batch_number' => 401, 'release_date' => now()->subDays(4), 'status' => 'published']);
        $module401 = BatchModule::query()->create(['course_batch_id' => $batch401->id, 'title' => 'Batch 401 module', 'sort_order' => 1]);
        $lesson401 = BatchLesson::query()->create(['batch_module_id' => $module401->id, 'title' => 'Batch 401 lesson', 'unlock_type' => 'immediate', 'is_published' => true, 'sort_order' => 1]);
        $batch402 = CourseBatch::query()->create(['course_id' => $course->id, 'batch_number' => 402, 'release_date' => now()->subHour(), 'status' => 'published']);
        $module402 = BatchModule::query()->create(['course_batch_id' => $batch402->id, 'title' => 'Batch 402 module', 'sort_order' => 1]);
        $lesson402 = BatchLesson::query()->create(['batch_module_id' => $module402->id, 'title' => 'Batch 402 lesson', 'unlock_type' => 'immediate', 'is_published' => true, 'sort_order' => 1]);

        $this->actingAs($student)->getJson('/api/student/courses/'.$course->id.'/batches')->assertOk()->assertJsonCount(2, 'batches');
        $this->actingAs($student)->postJson('/api/student/batch-lessons/'.$lesson401->id.'/progress', ['watched_seconds' => 60, 'video_duration_seconds' => 60, 'progress_percent' => 100, 'is_completed' => true])->assertOk();
        $this->actingAs($student)->postJson('/api/student/batch-lessons/'.$lesson402->id.'/progress', ['watched_seconds' => 15, 'video_duration_seconds' => 60, 'progress_percent' => 25])->assertOk();

        $this->assertDatabaseHas('batch_lesson_progress', ['enrollment_id' => $enrollment->id, 'course_batch_id' => $batch401->id, 'batch_lesson_id' => $lesson401->id, 'is_completed' => 1]);
        $this->assertDatabaseHas('batch_lesson_progress', ['enrollment_id' => $enrollment->id, 'course_batch_id' => $batch402->id, 'batch_lesson_id' => $lesson402->id, 'progress_percent' => 25]);
        $this->assertSame(2, BatchLessonProgress::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(0, LessonProgress::query()->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('batch_lessons', ['id' => $lesson401->id, 'title' => 'Batch 401 lesson']);
        $this->assertDatabaseHas('batch_lessons', ['id' => $lesson402->id, 'title' => 'Batch 402 lesson']);
    }

    public function test_trainer_can_manage_only_assigned_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Trainer Batch Course', 'slug' => 'trainer-batch-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'draft']);
        $batch = CourseBatch::query()->create(['course_id' => $course->id, 'batch_number' => 401, 'status' => 'draft']);

        $this->actingAs($trainer)->getJson('/api/trainer/batches/'.$batch->id.'/curriculum')->assertForbidden();
        $batch->trainers()->attach($trainer);
        $this->actingAs($trainer)->getJson('/api/trainer/batches/'.$batch->id.'/curriculum')->assertOk();
        $this->actingAs($trainer)->postJson('/api/trainer/batches/'.$batch->id.'/modules', ['title' => 'Assigned module'])->assertCreated();
        $this->actingAs($admin)->getJson('/api/admin/batches/'.$batch->id)->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_crud_the_four_supported_announcement_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Announcement Course', 'slug' => 'announcement-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);

        $this->actingAs($admin)->postJson('/api/admin/announcements', [
            'title' => 'Course notice', 'type' => 'notice', 'audience_type' => 'course', 'course_id' => $course->id,
            'description' => 'A course-specific notice.', 'external_url' => 'https://example.com/notice', 'status' => 'draft',
        ])->assertCreated()->assertJsonPath('announcement.status', 'draft');

        $announcement = Announcement::query()->firstOrFail();
        $this->actingAs($admin)->putJson('/api/admin/announcements/'.$announcement->id, [
            'title' => 'Published notice', 'type' => 'notice', 'audience_type' => 'course', 'course_id' => $course->id,
            'description' => 'Published course-specific notice.', 'external_url' => null, 'status' => 'published',
        ])->assertOk()->assertJsonPath('announcement.status', 'published');

        $this->actingAs($admin)->postJson('/api/admin/announcements', [
            'title' => 'Invalid course audience', 'type' => 'general', 'audience_type' => 'course',
            'description' => 'Missing course.', 'status' => 'draft',
        ])->assertUnprocessable();

        $this->actingAs($admin)->getJson('/api/admin/announcements?type=notice&audience_type=course&status=published')
            ->assertOk()->assertJsonPath('data.0.title', 'Published notice');
        $this->actingAs($admin)->deleteJson('/api/admin/announcements/'.$announcement->id)->assertOk();
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_dashboards_only_receive_authorized_published_announcements_and_reads_are_idempotent(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $otherStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $course = Course::query()->create(['title' => 'Eligible Course', 'slug' => 'eligible-course', 'short_description' => 'Course', 'price' => 100, 'status' => 'published']);
        $payment = Payment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'student_name' => $student->name, 'student_email' => $student->email, 'student_mobile' => '01700000000', 'amount' => 100, 'transaction_id' => 'ANNOUNCEMENT-001', 'status' => 'verified']);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'payment_id' => $payment->id, 'status' => 'active', 'enrolled_at' => now()]);

        $studentAnnouncement = Announcement::query()->create(['title' => 'Student update', 'type' => 'general', 'audience_type' => 'students', 'description' => 'For students.', 'status' => 'published']);
        $courseAnnouncement = Announcement::query()->create(['title' => 'Course update', 'type' => 'video', 'audience_type' => 'course', 'course_id' => $course->id, 'description' => 'For eligible students.', 'status' => 'published']);
        Announcement::query()->create(['title' => 'Trainer update', 'type' => 'notice', 'audience_type' => 'trainers', 'description' => 'For trainers.', 'status' => 'published']);
        Announcement::query()->create(['title' => 'Draft update', 'type' => 'blog', 'audience_type' => 'students', 'description' => 'Still private.', 'status' => 'draft']);

        $studentResponse = $this->actingAs($student)->getJson('/api/student/announcements')->assertOk();
        $this->assertCount(2, $studentResponse->json('announcements'));
        $studentResponse->assertJsonFragment(['title' => $studentAnnouncement->title])->assertJsonFragment(['title' => $courseAnnouncement->title]);
        $this->actingAs($otherStudent)->getJson('/api/student/announcements')->assertOk()->assertJsonCount(1, 'announcements')->assertJsonPath('announcements.0.title', $studentAnnouncement->title);
        $this->actingAs($trainer)->getJson('/api/trainer/announcements')->assertOk()->assertJsonCount(1, 'announcements')->assertJsonPath('announcements.0.audience_type', 'trainers');

        $this->actingAs($student)->getJson('/api/student/announcements/unread-count')->assertOk()->assertJsonPath('unread_count', 2);
        $this->actingAs($student)->postJson('/api/student/announcements/'.$studentAnnouncement->id.'/read')->assertOk()->assertJsonPath('unread_count', 1);
        $this->actingAs($student)->postJson('/api/student/announcements/'.$studentAnnouncement->id.'/read')->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertSame(1, AnnouncementRead::query()->where('announcement_id', $studentAnnouncement->id)->where('user_id', $student->id)->count());
        $this->actingAs($otherStudent)->postJson('/api/student/announcements/'.$courseAnnouncement->id.'/read')->assertNotFound();
    }
}

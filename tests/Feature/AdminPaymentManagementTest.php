<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_payments_and_transactions_with_student_and_course_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['name' => 'Payment Student', 'email' => 'payment.student@example.com', 'role' => 'student', 'status' => 'pending']);
        $course = Course::query()->create([
            'title' => 'Database Course', 'slug' => 'database-course', 'short_description' => 'Learn databases',
            'price' => 1200, 'status' => 'published',
        ]);
        Payment::query()->create([
            'user_id' => $student->id, 'course_id' => $course->id, 'student_name' => $student->name,
            'student_email' => $student->email, 'student_mobile' => '01700000000', 'amount' => 1200,
            'payment_method' => 'bkash_manual', 'transaction_id' => 'TXN-TEST-001', 'status' => 'pending',
        ]);

        $this->actingAs($admin)->getJson('/api/admin/payments?search=TXN-TEST-001')
            ->assertOk()->assertJsonPath('data.0.transaction_id', 'TXN-TEST-001')
            ->assertJsonPath('data.0.user.email', $student->email)
            ->assertJsonPath('data.0.course.title', $course->title);

        $this->actingAs($admin)->getJson('/api/admin/transactions?status=pending')
            ->assertOk()->assertJsonPath('data.0.student_mobile', '01700000000');
    }

    public function test_admin_can_read_payment_reports_and_course_fees_from_existing_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $course = Course::query()->create([
            'title' => 'Reporting Course', 'slug' => 'reporting-course', 'short_description' => 'Reporting test',
            'price' => 1500, 'discount_price' => 1000, 'status' => 'published',
        ]);
        Payment::query()->create([
            'course_id' => $course->id, 'student_name' => 'Report Student', 'student_email' => 'report@example.com',
            'student_mobile' => '01800000000', 'amount' => 1000, 'transaction_id' => 'TXN-TEST-002', 'status' => 'verified',
        ]);

        $this->actingAs($admin)->getJson('/api/admin/payment-reports')
            ->assertOk()->assertJsonPath('stats.total_count', 1)
            ->assertJsonPath('stats.verified_count', 1)
            ->assertJsonPath('stats.verified_amount', 1000);

        $this->actingAs($admin)->getJson('/api/admin/course-fees?search=reporting-course')
            ->assertOk()->assertJsonPath('data.0.title', 'Reporting Course')
            ->assertJsonPath('data.0.discount_price', '1000.00')
            ->assertJsonPath('data.0.payments_count', 1);
    }

    public function test_finance_endpoints_require_an_authenticated_admin(): void
    {
        $this->getJson('/api/admin/payments')->assertUnauthorized();
        $this->getJson('/api/admin/payment-reports')->assertUnauthorized();
        $this->getJson('/api/admin/course-fees')->assertUnauthorized();
    }

    public function test_admin_approval_activates_student_enrollment_and_lesson_access_without_duplicates(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'pending']);
        $course = Course::query()->create(['title' => 'Approved Course', 'slug' => 'approved-course', 'short_description' => 'Approved content', 'price' => 900, 'status' => 'published']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Module One', 'sort_order' => 1]);
        $lesson = Lesson::query()->create(['course_module_id' => $module->id, 'title' => 'Lesson One', 'video_reference' => 'video-id', 'unlock_type' => 'immediate', 'is_published' => true, 'sort_order' => 1]);
        $payment = Payment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'student_name' => $student->name, 'student_email' => $student->email, 'student_mobile' => '01900000000', 'amount' => 900, 'transaction_id' => 'TXN-APPROVE-001', 'status' => 'pending']);

        $this->actingAs($admin)->postJson('/api/admin/payments/'.$payment->id.'/verify', ['review_note' => 'bKash transaction checked'])->assertOk()->assertJsonPath('payment.status', 'verified');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'verified']);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'status' => 'active']);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $course->id, 'payment_id' => $payment->id, 'status' => 'active']);
        $this->assertSame(1, Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->count());

        $this->actingAs($student->refresh())->getJson('/api/student/courses/'.$course->id)->assertOk()->assertJsonPath('course.modules.0.lessons.0.id', $lesson->id);
        $this->actingAs($student)->getJson('/api/student/lessons/'.$lesson->id)->assertOk()->assertJsonPath('lesson.embed_url', 'https://www.youtube-nocookie.com/embed/video-id?rel=0&modestbranding=1');
        $this->actingAs($admin)->postJson('/api/admin/payments/'.$payment->id.'/verify')->assertUnprocessable();
    }

    public function test_rejection_does_not_activate_student_or_enrollment(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'pending']);
        $course = Course::query()->create(['title' => 'Rejected Course', 'slug' => 'rejected-course', 'short_description' => 'Rejected content', 'price' => 700, 'status' => 'published']);
        $payment = Payment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'student_name' => $student->name, 'student_email' => $student->email, 'student_mobile' => '01600000000', 'amount' => 700, 'transaction_id' => 'TXN-REJECT-001', 'status' => 'pending']);

        $this->actingAs($admin)->postJson('/api/admin/payments/'.$payment->id.'/reject', ['review_note' => 'Transaction could not be verified'])->assertOk()->assertJsonPath('payment.status', 'rejected');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id, 'course_id' => $course->id]);
        $this->actingAs($admin)->postJson('/api/admin/payments/'.$payment->id.'/reject', ['review_note' => 'Duplicate rejection'])->assertUnprocessable();
    }
}

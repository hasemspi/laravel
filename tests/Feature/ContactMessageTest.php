<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_can_submit_a_contact_message(): void
    {
        $response = $this->postJson('/api/contact-messages', [
            'name' => '  Amina Rahman  ',
            'email' => 'AMINA@example.com',
            'phone' => '01644-415771',
            'subject' => 'Course Admission',
            'message' => 'I would like more information about the next course intake.',
        ]);

        $response->assertCreated()->assertJsonPath('contact_message.id', 1);
        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Amina Rahman',
            'email' => 'amina@example.com',
            'subject' => 'Course Admission',
        ]);
    }

    public function test_contact_submission_validates_the_subject_and_contact_fields(): void
    {
        $this->postJson('/api/contact-messages', [
            'name' => '',
            'email' => 'not-an-email',
            'phone' => 'abc',
            'subject' => 'Not a valid subject',
            'message' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'phone', 'subject', 'message']);
    }

    public function test_active_admin_can_view_contact_messages_and_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $contactMessage = ContactMessage::query()->create([
            'name' => 'Amina Rahman',
            'email' => 'amina@example.com',
            'phone' => '01644-415771',
            'subject' => 'Technical Support',
            'message' => 'Please help me access my course account.',
        ]);

        $this->actingAs($admin)->getJson('/api/admin/contact-messages')
            ->assertOk()
            ->assertJsonPath('data.0.id', $contactMessage->id)
            ->assertJsonMissingPath('data.0.message');

        $this->actingAs($admin)->getJson('/api/admin/contact-messages/'.$contactMessage->id)
            ->assertOk()
            ->assertJsonPath('contact_message.message', 'Please help me access my course account.');
    }

    public function test_contact_view_is_protected_and_has_no_write_routes(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $contactMessage = ContactMessage::query()->create([
            'name' => 'Amina Rahman',
            'email' => 'amina@example.com',
            'phone' => '01644-415771',
            'subject' => 'Other',
            'message' => 'A message.',
        ]);

        $this->actingAs($student)->getJson('/api/admin/contact-messages')->assertForbidden();
        $this->actingAs($student)->getJson('/api/admin/contact-messages/'.$contactMessage->id)->assertForbidden();
        $this->actingAs($student)->postJson('/api/admin/contact-messages', [])->assertMethodNotAllowed();
        $this->actingAs($student)->deleteJson('/api/admin/contact-messages/'.$contactMessage->id)->assertMethodNotAllowed();
    }
}

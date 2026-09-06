<?php

namespace Tests\Unit;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_form_can_be_created(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'description' => 'A test form',
            'token' => 'test-token-123',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('forms', [
            'title' => 'Test Form',
            'token' => 'test-token-123',
        ]);
    }

    public function test_form_token_is_auto_generated(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotEmpty($form->token);
        $this->assertEquals(32, strlen($form->token));
    }

    public function test_form_has_fields_relationship(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        FormField::create([
            'form_id' => $form->id,
            'field_label' => 'Name',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $this->assertCount(1, $form->fields);
    }

    public function test_form_has_submissions_relationship(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student@test.com',
            'status' => 'validated',
        ]);

        $this->assertCount(1, $form->submissions);
    }

    public function test_form_has_creator_relationship(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals($this->admin->id, $form->creator->id);
    }

    public function test_form_is_open_when_active_and_within_dates(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'open_date' => now()->subDay(),
            'close_date' => now()->addDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->isOpen());
    }

    public function test_form_is_not_open_when_inactive(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_not_open_when_before_open_date(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'open_date' => now()->addDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_not_open_when_after_close_date(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'close_date' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_not_open_when_max_submissions_reached(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'max_submissions' => 1,
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student@test.com',
            'status' => 'validated',
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_open_when_no_dates_set(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->isOpen());
    }

    public function test_get_submission_count_returns_correct_count(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student1@test.com',
            'status' => 'validated',
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student2@test.com',
            'status' => 'validated',
        ]);

        $this->assertEquals(2, $form->getSubmissionCount());
    }
}

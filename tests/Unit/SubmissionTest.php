<?php

namespace Tests\Unit;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_submission_can_be_created(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'student_phone' => '+243123456789',
            'student_major' => 'Informatique',
            'status' => 'validated',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertDatabaseHas('submissions', [
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);
    }

    public function test_submission_has_form_relationship(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $this->assertEquals($this->form->id, $submission->form->id);
    }

    public function test_submission_has_files_relationship(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        SubmissionFile::create([
            'submission_id' => $submission->id,
            'original_name' => 'test.pdf',
            'stored_name' => 'test.pdf',
            'file_path' => 'test/test.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ]);

        $this->assertCount(1, $submission->files);
    }

    public function test_submission_unique_email_per_form(): void
    {
        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);
    }

    public function test_submission_allows_same_email_different_forms(): void
    {
        $form2 = Form::create([
            'title' => 'Test Form 2',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $submission2 = Submission::create([
            'form_id' => $form2->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $this->assertDatabaseHas('submissions', [
            'form_id' => $form2->id,
            'student_email' => 'john@test.com',
        ]);
    }

    public function test_submission_anonymous_code_is_unique(): void
    {
        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'anonymous_code' => 'ANON-123456',
            'status' => 'validated',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'jane@test.com',
            'anonymous_code' => 'ANON-123456',
            'status' => 'validated',
        ]);
    }
}

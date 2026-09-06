<?php

namespace Tests\Unit;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionFileTest extends TestCase
{
    use RefreshDatabase;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        
        $admin = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $admin->id,
        ]);

        $this->submission = Submission::create([
            'form_id' => $form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);
    }

    public function test_submission_file_can_be_created(): void
    {
        $file = SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
            'stored_name' => 'document.pdf',
            'file_path' => 'submissions/1/1/document.pdf',
            'file_size' => 1048576, // 1 MB
            'mime_type' => 'application/pdf',
        ]);

        $this->assertDatabaseHas('submission_files', [
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
        ]);
    }

    public function test_submission_file_has_submission_relationship(): void
    {
        $file = SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
            'stored_name' => 'document.pdf',
            'file_path' => 'submissions/1/1/document.pdf',
            'file_size' => 1048576,
            'mime_type' => 'application/pdf',
        ]);

        $this->assertEquals($this->submission->id, $file->submission->id);
    }

    public function test_formatted_size_returns_bytes(): void
    {
        $file = SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
            'stored_name' => 'document.pdf',
            'file_path' => 'submissions/1/1/document.pdf',
            'file_size' => 1024, // 1 Ko
            'mime_type' => 'application/pdf',
        ]);

        $this->assertEquals('1 Ko', $file->formatted_size);
    }

    public function test_formatted_size_returns_kilobytes(): void
    {
        $file = SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
            'stored_name' => 'document.pdf',
            'file_path' => 'submissions/1/1/document.pdf',
            'file_size' => 1048576, // 1 Mo
            'mime_type' => 'application/pdf',
        ]);

        $this->assertEquals('1 Mo', $file->formatted_size);
    }

    public function test_formatted_size_returns_megabytes(): void
    {
        $file = SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
            'stored_name' => 'document.pdf',
            'file_path' => 'submissions/1/1/document.pdf',
            'file_size' => 1073741824, // 1 Go
            'mime_type' => 'application/pdf',
        ]);

        $this->assertEquals('1 Go', $file->formatted_size);
    }

    public function test_formatted_size_returns_zero(): void
    {
        $file = SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'original_name' => 'document.pdf',
            'stored_name' => 'document.pdf',
            'file_path' => 'submissions/1/1/document.pdf',
            'file_size' => 0,
            'mime_type' => 'application/pdf',
        ]);

        $this->assertEquals('0 o', $file->formatted_size);
    }
}

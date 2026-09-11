<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->form = Form::create([
            'title' => 'Test Formulaire',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Nom complet',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Email',
            'field_type' => 'email',
            'required' => true,
            'order' => 1,
        ]);

        FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Fichier',
            'field_type' => 'file',
            'required' => true,
            'order' => 2,
        ]);
    }

    /**
     * Input name of the 'Fichier' file field (file_{id}).
     */
    private function fileFieldName(): string
    {
        $fileField = $this->form->fields()->where('field_type', 'file')->firstOrFail();

        return 'file_'.$fileField->id;
    }

    public function test_student_can_view_form(): void
    {
        $response = $this->get("/s/{$this->form->token}");

        $response->assertStatus(200);
        $response->assertSee('Test Formulaire');
        $response->assertSee('Nom complet');
    }

    public function test_student_cannot_view_inactive_form(): void
    {
        $this->form->update(['status' => 'inactive']);

        $response = $this->get("/s/{$this->form->token}");

        $response->assertStatus(200);
        $response->assertSee('Formulaire fermé');
    }

    public function test_student_cannot_view_expired_form(): void
    {
        $this->form->update(['close_date' => now()->subDay()]);

        $response = $this->get("/s/{$this->form->token}");

        $response->assertStatus(200);
        $response->assertSee('Formulaire fermé');
    }

    public function test_student_can_submit_form(): void
    {
        Storage::fake('local');

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'student_phone' => '+243123456789',
            'student_major' => 'Informatique',
            $this->fileFieldName() => [
                UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('submissions', [
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);
    }

    public function test_student_cannot_submit_duplicate_email(): void
    {
        Storage::fake('local');

        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            $this->fileFieldName() => [
                UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHas('error');
    }

    public function test_student_cannot_submit_to_closed_form(): void
    {
        $this->form->update(['status' => 'inactive']);

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
        ]);

        $response->assertSessionHas('error');
    }

    public function test_student_can_view_recap(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $response = $this->get("/s/{$this->form->token}/recap/{$submission->receipt_token}");

        $response->assertStatus(200);
        $response->assertSee('Récapitulatif');
        $response->assertSee('John Doe');
    }

    public function test_student_can_download_recap_pdf(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $response = $this->get("/s/{$this->form->token}/recap/{$submission->receipt_token}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_admin_can_view_submissions(): void
    {
        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $response = $this->get("/admin/forms/{$this->form->id}/submissions");

        $response->assertStatus(200);
    }

    public function test_admin_cannot_view_submission_from_another_form(): void
    {
        $otherForm = Form::create([
            'title' => 'Autre formulaire',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
        $submission = Submission::create([
            'form_id' => $otherForm->id,
            'student_email' => 'other@test.com',
            'status' => 'validated',
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $this->get("/admin/forms/{$this->form->id}/submissions/{$submission->id}")
            ->assertNotFound();
    }

    public function test_admin_can_view_submission(): void
    {
        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $response = $this->get("/admin/forms/{$this->form->id}/submissions/{$submission->id}");

        $response->assertStatus(200);
        $response->assertSee('John Doe');
    }

    public function test_admin_can_bulk_download_submissions(): void
    {
        Storage::fake('local');

        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $dir = "submissions/{$this->form->id}/{$submission->id}";
        UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf')
            ->storeAs($dir, 'rapport.pdf', 'local');
        UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf')
            ->storeAs($dir, 'rapport_2.pdf', 'local');

        SubmissionFile::create([
            'submission_id' => $submission->id,
            'field_label' => 'RAPPORT',
            'original_name' => 'rapport.pdf',
            'stored_name' => 'rapport.pdf',
            'file_path' => "{$dir}/rapport.pdf",
            'file_size' => 100,
            'mime_type' => 'application/pdf',
        ]);
        SubmissionFile::create([
            'submission_id' => $submission->id,
            'field_label' => 'RAPPORT',
            'original_name' => 'rapport.pdf',
            'stored_name' => 'rapport_2.pdf',
            'file_path' => "{$dir}/rapport_2.pdf",
            'file_size' => 100,
            'mime_type' => 'application/pdf',
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $response = $this->get("/admin/forms/{$this->form->id}/submissions/bulk-download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');

        // The archive must contain every uploaded file, even when two files
        // share the same original name, inside a folder named after the
        // student (not the email).
        $zip = new \ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('Doe_John/RAPPORT/rapport.pdf', $names);
        $this->assertContains('Doe_John/RAPPORT/rapport_2.pdf', $names);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('john@test.com', $name);
        }
        $zip->close();

        // The archive is written to the real storage/app directory, so clean
        // it up (deleteFileAfterSend only runs when the response is sent).
        @unlink($response->getFile()->getPathname());
    }

    public function test_admin_can_download_single_submission_zip(): void
    {
        Storage::fake('local');

        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $dir = "submissions/{$this->form->id}/{$submission->id}";
        UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf')
            ->storeAs($dir, 'rapport.pdf', 'local');
        UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf')
            ->storeAs($dir, 'rapport_2.pdf', 'local');

        SubmissionFile::create([
            'submission_id' => $submission->id,
            'field_label' => 'RAPPORT',
            'original_name' => 'rapport.pdf',
            'stored_name' => 'rapport.pdf',
            'file_path' => "{$dir}/rapport.pdf",
            'file_size' => 100,
            'mime_type' => 'application/pdf',
        ]);
        SubmissionFile::create([
            'submission_id' => $submission->id,
            'field_label' => 'RAPPORT',
            'original_name' => 'rapport.pdf',
            'stored_name' => 'rapport_2.pdf',
            'file_path' => "{$dir}/rapport_2.pdf",
            'file_size' => 100,
            'mime_type' => 'application/pdf',
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $response = $this->get("/admin/forms/{$this->form->id}/submissions/{$submission->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');

        $zip = new \ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('Doe_John/RAPPORT/rapport.pdf', $names);
        $this->assertContains('Doe_John/RAPPORT/rapport_2.pdf', $names);
        $zip->close();

        @unlink($response->getFile()->getPathname());
    }

    public function test_two_uploaded_files_with_same_name_are_both_stored(): void
    {
        Storage::fake('local');

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            $this->fileFieldName() => [
                UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();

        $submission = Submission::where('form_id', $this->form->id)
            ->where('student_email', 'john@test.com')
            ->first();
        $this->assertNotNull($submission);
        $this->assertSame(2, $submission->files()->count());
        $this->assertSame(['Fichier', 'Fichier'], $submission->files()->pluck('field_label')->all());

        // Both uploads must have their own file on disk, not overwrite each other.
        $paths = $submission->files()->pluck('file_path')->all();
        $this->assertCount(2, array_unique($paths));
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_anonymous_form_is_accessible(): void
    {
        $this->form->update(['is_anonymous' => true]);

        $response = $this->get("/s/{$this->form->token}");

        $response->assertStatus(200);
        $response->assertSee('Test Formulaire');
    }

    public function test_anonymous_submission_generates_code(): void
    {
        $this->form->update(['is_anonymous' => true]);

        Storage::fake('local');

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            $this->fileFieldName() => [
                UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $submission = Submission::where('form_id', $this->form->id)
            ->where('student_email', 'john@test.com')
            ->first();
        $this->assertNotNull($submission);
        $this->assertStringStartsWith('ANON-', $submission->anonymous_code);
    }

    public function test_student_can_submit_form_with_mail_labeled_email_field(): void
    {
        Storage::fake('local');

        // Mirror the user's form: the email field is labeled 'MAIL' (uppercase),
        // which must map to student_email instead of a dead student_mail input.
        FormField::where('form_id', $this->form->id)
            ->where('field_label', 'Email')
            ->update(['field_label' => 'MAIL']);

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            $this->fileFieldName() => [
                UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('submissions', [
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);
    }

    public function test_student_cannot_access_another_submission_receipt(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $response = $this->get("/s/{$this->form->token}/recap/".str_repeat('A', 64));

        $response->assertNotFound();
        $this->assertNotEmpty($submission->receipt_token);
    }

    public function test_student_must_upload_file_when_file_field_is_required(): void
    {
        Storage::fake('local');

        // Required file field: submitting without any file must fail validation.
        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
        ]);

        $response->assertSessionHasErrors($this->fileFieldName());
        $this->assertDatabaseMissing('submissions', [
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
        ]);
    }
}

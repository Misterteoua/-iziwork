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

class SubmissionAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $form;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $this->form = Form::create([
            'title' => 'Test Formulaire',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Devoir',
            'field_type' => 'file',
            'required' => true,
            'order' => 0,
        ]);

        $this->submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'Jean Dupont',
            'student_email' => 'jean@test.com',
            'student_phone' => '+225 0102030405',
            'student_major' => 'GL',
            'receipt_token' => Str()->random(64),
            'status' => 'validated',
            'ip_address' => '203.0.113.7',
        ]);

        SubmissionFile::create([
            'submission_id' => $this->submission->id,
            'field_label' => 'Devoir',
            'original_name' => 'ancien.pdf',
            'stored_name' => 'ancien-uuid.pdf',
            'file_path' => 'submissions/'.$this->form->id.'/'.$this->submission->id.'/ancien-uuid.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ]);

        Storage::disk('local')->put(
            'submissions/'.$this->form->id.'/'.$this->submission->id.'/ancien-uuid.pdf',
            '%PDF-1.4 ancien'
        );
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('submissions');
        Storage::disk('local')->deleteDirectory('backups');

        parent::tearDown();
    }

    /*
     * ---- Édition ----
     */

    public function test_edit_page_displays_submission_data(): void
    {
        $response = $this->get("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/edit");

        $response->assertStatus(200);
        $response->assertSee('Jean Dupont');
        $response->assertSee('jean@test.com');
        $response->assertSee('Pièces jointes');
    }

    public function test_submission_fields_can_be_updated(): void
    {
        $response = $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}", [
            'student_name' => 'Jean Dupont-Corrigé',
            'student_email' => 'jean.corrigé@test.com',
            'student_phone' => '+225 0999999999',
            'student_major' => 'GB',
            'status' => 'pending',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->submission->refresh();

        $this->assertSame('Jean Dupont-Corrigé', $this->submission->student_name);
        $this->assertSame('jean.corrigé@test.com', $this->submission->student_email);
        $this->assertSame('pending', $this->submission->status);

        // Audit trail fields stay untouched.
        $this->assertSame('203.0.113.7', $this->submission->ip_address);
        $this->assertTrue($this->submission->created_at->isToday());
    }

    public function test_email_can_be_blanked_when_student_made_a_mistake(): void
    {
        $response = $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}", [
            'student_name' => 'Jean Dupont',
            'student_email' => '',
            'student_phone' => null,
            'student_major' => null,
            'status' => 'validated',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertNull($this->submission->fresh()->student_email);
    }

    public function test_update_rejects_another_submissions_email(): void
    {
        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'pris@test.com',
            'status' => 'validated',
        ]);

        $response = $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}", [
            'student_name' => 'Jean Dupont',
            'student_email' => 'pris@test.com',
            'student_phone' => null,
            'student_major' => null,
            'status' => 'validated',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('student_email');

        $this->assertSame('jean@test.com', $this->submission->fresh()->student_email);
    }

    public function test_keeping_own_email_is_allowed(): void
    {
        $response = $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}", [
            'student_name' => 'Jean Dupont',
            'student_email' => 'jean@test.com',
            'student_phone' => null,
            'student_major' => null,
            'status' => 'validated',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_ip_and_created_at_are_immutable(): void
    {
        $originalCreatedAt = $this->submission->created_at;

        $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}", [
            'student_name' => 'Nouveau Nom',
            'student_email' => 'nouveau@test.com',
            'student_phone' => null,
            'student_major' => null,
            'status' => 'validated',
        ]);

        $this->submission->refresh();

        $this->assertSame('203.0.113.7', $this->submission->ip_address);
        $this->assertTrue($originalCreatedAt->equalTo($this->submission->created_at));
    }

    /*
     * ---- Suppression ----
     */

    public function test_submission_can_be_deleted_with_its_files(): void
    {
        $storedPath = 'submissions/'.$this->form->id.'/'.$this->submission->id.'/ancien-uuid.pdf';
        Storage::disk('local')->assertExists($storedPath);

        $response = $this->delete("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}");

        $response->assertRedirect("/admin/forms/{$this->form->id}/submissions");
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('submissions', ['id' => $this->submission->id]);
        $this->assertDatabaseMissing('submission_files', ['submission_id' => $this->submission->id]);
        Storage::disk('local')->assertMissing($storedPath);
    }

    /*
     * ---- Pièces jointes ----
     */

    public function test_a_file_can_be_added_to_an_existing_submission(): void
    {
        $response = $this->post("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/files", [
            'file' => UploadedFile::fake()->create('complement.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(2, $this->submission->files()->count());
    }

    /*
     * Même tolérance que pour les étudiants : un ZIP envoyé depuis Firefox
     * (« application/x-zip-compressed ») doit être accepté par l'admin.
     */
    public function test_a_zip_declared_as_zip_compressed_can_be_added(): void
    {
        $response = $this->post("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/files", [
            'file' => UploadedFile::fake()->create('archive.zip', 100, 'application/x-zip-compressed'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(2, $this->submission->files()->count());
    }

    public function test_a_file_can_be_replaced_in_place(): void
    {
        $file = $this->submission->files()->first();
        $oldStoredPath = $file->file_path;

        $response = $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/files/{$file->id}", [
            'file' => UploadedFile::fake()->create('correction.pdf', 200, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $file->refresh();

        // Same row, new content: identity and creation date preserved.
        $this->assertSame($file->id, $file->id);
        $this->assertSame('correction.pdf', $file->original_name);
        $this->assertNotSame($oldStoredPath, $file->file_path);
        Storage::disk('local')->assertMissing($oldStoredPath);
        Storage::disk('local')->assertExists($file->file_path);
        $this->assertSame(1, $this->submission->files()->count());
    }

    public function test_a_file_can_be_deleted(): void
    {
        $file = $this->submission->files()->first();
        $storedPath = $file->file_path;

        $response = $this->delete("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/files/{$file->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('submission_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($storedPath);
        $this->assertSame(0, $this->submission->files()->count());
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        $response = $this->post("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/files", [
            'file' => UploadedFile::fake()->create('virus.exe', 100),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('file');
        $this->assertSame(1, $this->submission->files()->count());
    }

    /*
     * ---- Cloisonnement ----
     */

    public function test_submission_of_another_form_cannot_be_edited(): void
    {
        $otherForm = Form::create([
            'title' => 'Autre formulaire',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->put("/admin/forms/{$otherForm->id}/submissions/{$this->submission->id}", [
            'student_name' => 'Pirate',
            'student_email' => 'pirate@test.com',
            'student_phone' => null,
            'student_major' => null,
            'status' => 'validated',
        ]);

        $response->assertStatus(404);
        $this->assertSame('Jean Dupont', $this->submission->fresh()->student_name);
    }

    public function test_guest_cannot_edit_or_delete(): void
    {
        $this->app['session']->flush();

        $this->get("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}/edit")
            ->assertRedirect('/login');
        $this->put("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}", [])
            ->assertRedirect('/login');
        $this->delete("/admin/forms/{$this->form->id}/submissions/{$this->submission->id}")
            ->assertRedirect('/login');
    }
}

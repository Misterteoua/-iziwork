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
        Storage::fake('public');

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'student_phone' => '+243123456789',
            'student_major' => 'Informatique',
            'files' => [
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
        Submission::create([
            'form_id' => $this->form->id,
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
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

        $response = $this->get("/s/{$this->form->token}/recap/{$submission->id}");

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

        $response = $this->get("/s/{$this->form->token}/recap/{$submission->id}/pdf");

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

        Storage::fake('public');

        $response = $this->post("/s/{$this->form->token}", [
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'files' => [
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
}

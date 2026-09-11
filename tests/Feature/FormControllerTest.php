<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

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
    }

    public function test_admin_can_access_dashboard(): void
    {
        $response = $this->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Tableau de bord');
    }

    public function test_guest_cannot_access_admin_routes(): void
    {
        $this->app['session']->flush();

        $response = $this->get('/admin');

        $response->assertRedirect('/login');
    }

    public function test_admin_can_view_forms_index(): void
    {
        $response = $this->get('/admin/forms');

        $response->assertStatus(200);
        $response->assertSee('Formulaires');
    }

    public function test_admin_can_create_form(): void
    {
        $response = $this->post('/admin/forms', [
            'title' => 'Test Formulaire',
            'description' => 'Description du formulaire',
            'field_labels' => ['Nom complet', 'Email'],
            'field_types' => ['text', 'email'],
            'field_requireds' => ['0'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('forms', [
            'title' => 'Test Formulaire',
        ]);
    }

    public function test_admin_can_view_form(): void
    {
        $form = Form::create([
            'title' => 'Test Formulaire',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->get("/admin/forms/{$form->id}");

        $response->assertStatus(200);
        $response->assertSee('Test Formulaire');
    }

    public function test_admin_can_update_form(): void
    {
        $form = Form::create([
            'title' => 'Ancien titre',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->put("/admin/forms/{$form->id}", [
            'title' => 'Nouveau titre',
            'field_labels' => ['Champ 1'],
            'field_types' => ['text'],
            'field_requireds' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'title' => 'Nouveau titre',
        ]);
    }

    public function test_admin_can_toggle_form_status(): void
    {
        $form = Form::create([
            'title' => 'Test Formulaire',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->patch("/admin/forms/{$form->id}/toggle-status");

        $response->assertRedirect();
        $this->assertDatabaseHas('forms', [
            'id' => $form->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_delete_form(): void
    {
        $form = Form::create([
            'title' => 'Test Formulaire',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->delete("/admin/forms/{$form->id}");

        $response->assertRedirect('/admin/forms');
        $this->assertDatabaseMissing('forms', [
            'id' => $form->id,
        ]);
    }

    public function test_admin_can_copy_link(): void
    {
        $form = Form::create([
            'title' => 'Test Formulaire',
            'token' => 'test-token-123',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson("/admin/forms/{$form->id}/copy-link");

        $response->assertStatus(200);
        $response->assertJsonFragment(['link' => route('submit.form', $form->token)]);
    }

    public function test_admin_form_validation_rejects_unknown_field_types_and_oversized_descriptions(): void
    {
        $response = $this->post('/admin/forms', [
            'title' => 'Test Formulaire',
            'description' => str_repeat('x', 10001),
            'field_labels' => ['Champ'],
            'field_types' => ['unknown'],
        ]);

        $response->assertSessionHasErrors(['description', 'field_types.0']);
        $this->assertDatabaseMissing('forms', ['title' => 'Test Formulaire']);
    }

    public function test_admin_can_view_submissions(): void
    {
        $form = Form::create([
            'title' => 'Test Formulaire',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->get("/admin/forms/{$form->id}/submissions");

        $response->assertStatus(200);
    }
}

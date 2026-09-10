<?php

namespace Tests\Unit;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormFieldTest extends TestCase
{
    use RefreshDatabase;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();
        
        $admin = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $admin->id,
        ]);
    }

    public function test_form_field_can_be_created(): void
    {
        $field = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Nom complet',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $this->assertDatabaseHas('form_fields', [
            'form_id' => $this->form->id,
            'field_label' => 'Nom complet',
            'field_type' => 'text',
        ]);
    }

    public function test_form_field_has_form_relationship(): void
    {
        $field = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Email',
            'field_type' => 'email',
            'required' => true,
            'order' => 1,
        ]);

        $this->assertEquals($this->form->id, $field->form->id);
    }

    public function test_form_field_types(): void
    {
        $textField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Nom',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $emailField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Email',
            'field_type' => 'email',
            'required' => true,
            'order' => 1,
        ]);

        $telField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Téléphone',
            'field_type' => 'tel',
            'required' => false,
            'order' => 2,
        ]);

        $fileField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Fichier',
            'field_type' => 'file',
            'required' => true,
            'order' => 3,
        ]);

        $this->assertEquals('text', $textField->field_type);
        $this->assertEquals('email', $emailField->field_type);
        $this->assertEquals('tel', $telField->field_type);
        $this->assertEquals('file', $fileField->field_type);
    }

    public function test_form_field_select_and_checkbox_types(): void
    {
        $selectField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Filière',
            'field_type' => 'select',
            'required' => true,
            'order' => 0,
            'options' => ['TP', 'BAT', 'INFO', 'GE', 'MECA', 'ENERG'],
        ]);

        $checkboxField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Confirmation',
            'field_type' => 'checkbox',
            'required' => false,
            'order' => 1,
        ]);

        $this->assertTrue($selectField->isSelect());
        $this->assertTrue($checkboxField->isCheckbox());
        $this->assertEquals(['TP', 'BAT', 'INFO', 'GE', 'MECA', 'ENERG'], $selectField->getOptionsList());
    }

    public function test_form_field_is_required(): void
    {
        $requiredField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Nom',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $optionalField = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Téléphone',
            'field_type' => 'tel',
            'required' => false,
            'order' => 1,
        ]);

        $this->assertTrue($requiredField->required);
        $this->assertFalse($optionalField->required);
    }

    public function test_form_fields_are_ordered(): void
    {
        $field1 = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Deuxième',
            'field_type' => 'text',
            'required' => true,
            'order' => 1,
        ]);

        $field2 = FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Premier',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $fields = $this->form->fields()->get();
        
        $this->assertEquals('Premier', $fields->first()->field_label);
        $this->assertEquals('Deuxième', $fields->last()->field_label);
    }

    public function test_form_fields_are_deleted_when_form_is_deleted(): void
    {
        FormField::create([
            'form_id' => $this->form->id,
            'field_label' => 'Nom',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $this->form->delete();

        $this->assertDatabaseMissing('form_fields', [
            'form_id' => $this->form->id,
        ]);
    }
}

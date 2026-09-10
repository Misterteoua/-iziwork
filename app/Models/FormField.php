<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'field_label',
        'field_type',
        'required',
        'order',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
        ];
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Get the input name used for this field in the submission form.
     *
     * Each file field sends its uploads under its own name (file_{id}) so the
     * submitted files can be associated back to the field they were uploaded
     * into. The standard student fields (name, email, phone, filière) are
     * matched by their label.
     */
    public function getFieldName(): string
    {
        // File fields use their own input name
        if ($this->field_type === 'file') {
            return 'file_' . $this->id;
        }

        return match (strtolower($this->field_label)) {
            'nom complet', 'nom', 'name' => 'student_name',
            'email', 'adresse email', 'mail', 'adresse mail', 'e-mail', 'adresse e-mail', 'courriel' => 'student_email',
            'téléphone', 'telephone', 'tel', 'phone' => 'student_phone',
            'filière', 'filiere', 'major', 'spécialité' => 'student_major',
            default => 'student_' . strtolower(str_replace(' ', '_', $this->field_label)),
        };
    }

    /**
     * Check if this field is a select (liste déroulante)
     */
    public function isSelect(): bool
    {
        return $this->field_type === 'select';
    }

    /**
     * Check if this field is a checkbox
     */
    public function isCheckbox(): bool
    {
        return $this->field_type === 'checkbox';
    }

    /**
     * Get parsed options for select fields
     */
    public function getOptionsList(): array
    {
        if (!$this->options || !is_array($this->options)) {
            return [];
        }

        return $this->options;
    }
}

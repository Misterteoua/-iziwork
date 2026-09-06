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

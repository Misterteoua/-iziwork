<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->has('admin_user.id');
    }

    public function rules(): array
    {
        $fieldCount = count((array) $this->input('field_labels', []));

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'open_date' => ['nullable', 'date'],
            'close_date' => ['nullable', 'date', 'after_or_equal:open_date'],
            'max_submissions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'field_labels' => ['required', 'array', 'min:1', 'max:50'],
            'field_labels.*' => ['required', 'string', 'max:255'],
            'field_types' => ['required', 'array', 'size:'.$fieldCount],
            'field_types.*' => ['required', Rule::in(['text', 'email', 'tel', 'file', 'select', 'checkbox'])],
            'field_requireds' => ['sometimes', 'array'],
            'field_requireds.*' => ['integer', 'min:0'],
            'field_options' => ['sometimes', 'array'],
            'field_options.*' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

@php
    $fieldLabel = $field->field_label ?? '';
    $fieldType = $field->field_type ?? 'text';
    $fieldRequired = $field->required ?? true;
    $fieldOptions = $field->options ?? null;
    $isSelect = ($fieldType === 'select');
@endphp

<div class="field-row flex flex-col gap-3 p-3 sm:p-4 bg-gray-50 rounded-lg">
    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
        <div class="flex-1 min-w-0">
            <label class="block text-xs font-medium text-gray-500 mb-1">Libellé du champ</label>
            <input type="text" name="field_labels[]" required
                   class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 text-sm transition-colors duration-200"
                   placeholder="Ex: Filière…"
                   value="{{ $fieldLabel }}">
        </div>
        <div class="w-full sm:w-36">
            <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
            <select name="field_types[]" 
                    class="field-type-select block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 text-sm transition-colors duration-200"
                    onchange="toggleOptions(this.closest('.field-row'), this.value)"
                    style="background-color: white;">
                <option value="text" {{ $fieldType === 'text' ? 'selected' : '' }}>Texte</option>
                <option value="email" {{ $fieldType === 'email' ? 'selected' : '' }}>Email</option>
                <option value="tel" {{ $fieldType === 'tel' ? 'selected' : '' }}>Téléphone</option>
                <option value="file" {{ $fieldType === 'file' ? 'selected' : '' }}>Fichier</option>
                <option value="select" {{ $fieldType === 'select' ? 'selected' : '' }}>Liste déroulante</option>
                <option value="checkbox" {{ $fieldType === 'checkbox' ? 'selected' : '' }}>Case à cocher</option>
            </select>
        </div>
        <div class="flex items-center gap-3 sm:mt-5">
            <div class="flex items-center">
                <input type="checkbox" name="field_requireds[]" value="{{ $index }}"
                       class="h-4 w-4 text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500 border-gray-300 rounded transition-colors duration-200"
                       {{ $fieldRequired ? 'checked' : '' }}>
                <span class="ml-2 text-sm text-gray-500">Requis</span>
            </div>
            <button type="button" onclick="removeField(this)" 
                    class="h-9 w-9 flex items-center justify-center text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors duration-200"
                    aria-label="Supprimer ce champ">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
            </button>
        </div>
    </div>
    <div class="field-options-wrapper {{ $isSelect ? '' : 'hidden' }}">
        <label class="block text-xs font-medium text-gray-500 mb-1">Options de la liste (une par ligne)</label>
        <textarea name="field_options[]" rows="3"
                  class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 text-sm transition-colors duration-200"
                  placeholder="Option 1&#10;Option 2&#10;Option 3">{{ is_array($fieldOptions) ? implode("\n", $fieldOptions) : '' }}</textarea>
    </div>
</div>

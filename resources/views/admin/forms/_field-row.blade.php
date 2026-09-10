@php
    $fieldLabel = $field->field_label ?? '';
    $fieldType = $field->field_type ?? 'text';
    $fieldRequired = $field->required ?? true;
    $fieldOptions = $field->options ?? null;
    $isSelect = ($fieldType === 'select');
@endphp

<div class="field-row bg-slate-50/80 rounded-xl border border-slate-200/70 p-4 space-y-3">
    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
        <div class="flex-1 min-w-0">
            <label class="block text-xs font-medium text-slate-500 mb-1.5">Libellé du champ</label>
            <input type="text" name="field_labels[]" required
                   class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white"
                   placeholder="Ex: Filière…"
                   value="{{ $fieldLabel }}">
        </div>
        <div class="w-full sm:w-40">
            <label class="block text-xs font-medium text-slate-500 mb-1.5">Type</label>
            <select name="field_types[]"
                    class="field-type-select w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white"
                    onchange="toggleOptions(this.closest('.field-row'), this.value)">
                <option value="text" {{ $fieldType === 'text' ? 'selected' : '' }}>Texte</option>
                <option value="email" {{ $fieldType === 'email' ? 'selected' : '' }}>Email</option>
                <option value="tel" {{ $fieldType === 'tel' ? 'selected' : '' }}>Téléphone</option>
                <option value="file" {{ $fieldType === 'file' ? 'selected' : '' }}>Fichier</option>
                <option value="select" {{ $fieldType === 'select' ? 'selected' : '' }}>Liste déroulante</option>
                <option value="checkbox" {{ $fieldType === 'checkbox' ? 'selected' : '' }}>Case à cocher</option>
            </select>
        </div>
        <div class="flex items-center gap-3 sm:mt-6">
            <label class="inline-flex items-center cursor-pointer">
                <input type="checkbox" name="field_requireds[]" value="{{ $index }}"
                       class="sr-only peer"
                       {{ $fieldRequired ? 'checked' : '' }}>
                <div class="w-9 h-5 bg-slate-300 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 rounded-full peer peer-checked:after:translate-x-4 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-brand-600"></div>
                <span class="ml-2 text-xs font-medium text-slate-600">Requis</span>
            </label>
            <button type="button" onclick="removeField(this)"
                    class="h-9 w-9 flex items-center justify-center rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors duration-150"
                    aria-label="Supprimer ce champ">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
            </button>
        </div>
    </div>
    <div class="field-options-wrapper {{ $isSelect ? '' : 'hidden' }}">
        <label class="block text-xs font-medium text-slate-500 mb-1.5">Options de la liste (une par ligne)</label>
        <textarea name="field_options[]" rows="3"
                  class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white"
                  placeholder="Option 1&#10;Option 2&#10;Option 3">{{ is_array($fieldOptions) ? implode("\n", $fieldOptions) : '' }}</textarea>
    </div>
</div>
@extends('layouts.app')

@section('title', 'Nouveau formulaire')

@section('content')
<div class="px-4 sm:px-0 max-w-3xl">
    <div class="mb-6">
        <a href="{{ route('admin.forms.index') }}" class="text-sm text-indigo-600 hover:text-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
            ← Retour aux formulaires
        </a>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mt-2">Nouveau formulaire</h1>
    </div>

    <form method="POST" action="{{ route('admin.forms.store') }}" class="space-y-6">
        @csrf
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Informations générales</h2>
            
            <div class="space-y-4">
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Titre <span class="text-red-500" aria-hidden="true">*</span></label>
                    <input type="text" name="title" id="title" required
                           class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                           value="{{ old('title') }}"
                           placeholder="Ex: Examen final - Mathématiques…">
                    @error('title')
                    <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="3"
                              class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                              placeholder="Description optionnelle du formulaire…">{{ old('description') }}</textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Paramètres</h2>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="open_date" class="block text-sm font-medium text-gray-700">Date/heure d'ouverture</label>
                    <input type="datetime-local" name="open_date" id="open_date"
                           class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                           value="{{ old('open_date') }}">
                </div>
                
                <div>
                    <label for="close_date" class="block text-sm font-medium text-gray-700">Date/heure de fermeture</label>
                    <input type="datetime-local" name="close_date" id="close_date"
                           class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                           value="{{ old('close_date') }}">
                </div>
                
                <div>
                    <label for="max_submissions" class="block text-sm font-medium text-gray-700">Nombre max de dépôts</label>
                    <input type="number" name="max_submissions" id="max_submissions"
                           class="mt-1 block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                           value="{{ old('max_submissions') }}"
                           placeholder="Illimité si vide…"
                           min="1">
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="is_anonymous" id="is_anonymous" value="1"
                           class="h-4 w-4 text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500 border-gray-300 rounded transition-colors duration-200"
                           {{ old('is_anonymous') ? 'checked' : '' }}>
                    <label for="is_anonymous" class="ml-2 block text-sm text-gray-700">
                        Activer l'anonymat
                    </label>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Champs du formulaire</h2>
                <button type="button" onclick="addField()" 
                        class="inline-flex items-center px-3 py-1 border border-transparent text-sm font-medium rounded-lg text-indigo-600 bg-indigo-50 hover:bg-indigo-100 focus-visible:ring-2 focus-visible:ring-indigo-500 transition-colors duration-200"
                        aria-label="Ajouter un champ">
                    <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter
                </button>
            </div>
            
            <div id="fields-container" class="space-y-4">
                @php $fieldIndex = 0; @endphp
                @include('admin.forms._field-row', ['index' => 0])
            </div>
            
            <p class="mt-4 text-sm text-gray-500">
                Types disponibles : texte, email, téléphone, fichier, liste déroulante, case à cocher.
            </p>
        </div>

        <div class="flex flex-col sm:flex-row justify-end gap-3">
            <a href="{{ route('admin.forms.index') }}" 
               class="px-4 py-2.5 border border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 transition-colors duration-200 text-center order-2 sm:order-1">
                Annuler
            </a>
            <button type="submit" 
                    class="px-6 py-2.5 border border-transparent text-sm font-medium rounded-lg text-white gradient-bg hover:opacity-90 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 transition-opacity duration-200 order-1 sm:order-2">
                Créer le formulaire
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    let fieldCount = 1;
    
    function addField() {
        const container = document.getElementById('fields-container');
        const div = document.createElement('div');
        div.innerHTML = getFieldHtml(fieldCount);
        const newRow = div.firstElementChild;
        container.appendChild(newRow);
        
        newRow.querySelector('.field-type-select').addEventListener('change', function() {
            toggleOptions(newRow, this.value);
        });
        
        fieldCount++;
    }
    
    function removeField(button) {
        const container = document.getElementById('fields-container');
        if (container.children.length > 1) {
            button.closest('.field-row').remove();
        }
    }
    
    function toggleOptions(row, type) {
        const optionsDiv = row.querySelector('.field-options-wrapper');
        if (type === 'select') {
            optionsDiv.classList.remove('hidden');
        } else {
            optionsDiv.classList.add('hidden');
        }
    }
    
    function getFieldHtml(index) {
        return `
        <div class="field-row flex flex-col gap-3 p-3 sm:p-4 bg-gray-50 rounded-lg">
            <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                <div class="flex-1 min-w-0">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Libellé du champ</label>
                    <input type="text" name="field_labels[]" required
                           class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 text-sm transition-colors duration-200"
                           placeholder="Ex: Filière…">
                </div>
                <div class="w-full sm:w-36">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                    <select name="field_types[]" 
                            class="field-type-select block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 text-sm transition-colors duration-200"
                            onchange="toggleOptions(this.closest('.field-row'), this.value)"
                            style="background-color: white;">
                        <option value="text">Texte</option>
                        <option value="email">Email</option>
                        <option value="tel">Téléphone</option>
                        <option value="file">Fichier</option>
                        <option value="select">Liste déroulante</option>
                        <option value="checkbox">Case à cocher</option>
                    </select>
                </div>
                <div class="flex items-center gap-3 sm:mt-5">
                    <div class="flex items-center">
                        <input type="checkbox" name="field_requireds[]" value="${index}"
                               class="h-4 w-4 text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500 border-gray-300 rounded transition-colors duration-200"
                               checked>
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
            <div class="field-options-wrapper hidden">
                <label class="block text-xs font-medium text-gray-500 mb-1">Options de la liste (une par ligne)</label>
                <textarea name="field_options[]" rows="3"
                          class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 text-sm transition-colors duration-200"
                          placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
            </div>
        </div>`;
    }
</script>
@endpush

@extends('layouts.app')

@section('title', 'Nouveau formulaire')

@section('content')
<div class="px-4 sm:px-0 max-w-3xl">
    {{-- Header --}}
    <div class="mb-8">
        <a href="{{ route('admin.forms.index') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux formulaires
        </a>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 mt-3">Nouveau formulaire</h1>
        <p class="mt-1 text-sm text-slate-500">Configurez votre formulaire de dépôt de travaux</p>
    </div>

    <form method="POST" action="{{ route('admin.forms.store') }}" class="space-y-6">
        @csrf

        {{-- Section: Informations --}}
        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <div class="flex items-center gap-3 mb-6">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 shrink-0">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Informations générales</h2>
                    <p class="text-xs text-slate-500">Le titre et la description du formulaire</p>
                </div>
            </div>

            <div class="space-y-5">
                <div>
                    <label for="title" class="block text-sm font-medium text-slate-700 mb-1.5">Titre <span class="text-red-500" aria-hidden="true">*</span></label>
                    <input type="text" name="title" id="title" required
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150"
                           value="{{ old('title') }}"
                           placeholder="Ex: Examen final - Mathématiques…">
                    @error('title')
                    <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                    <textarea name="description" id="description" rows="3"
                              class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150"
                              placeholder="Description optionnelle du formulaire…">{{ old('description') }}</textarea>
                </div>
            </div>
        </section>

        {{-- Section: Paramètres --}}
        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <div class="flex items-center gap-3 mb-6">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 shrink-0">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Paramètres</h2>
                    <p class="text-xs text-slate-500">Dates, limite de dépôts et anonymat</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="open_date" class="block text-sm font-medium text-slate-700 mb-1.5">Date/heure d'ouverture</label>
                    <input type="datetime-local" name="open_date" id="open_date"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150"
                           value="{{ old('open_date') }}">
                </div>

                <div>
                    <label for="close_date" class="block text-sm font-medium text-slate-700 mb-1.5">Date/heure de fermeture</label>
                    <input type="datetime-local" name="close_date" id="close_date"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150"
                           value="{{ old('close_date') }}">
                </div>

                <div>
                    <label for="max_submissions" class="block text-sm font-medium text-slate-700 mb-1.5">Nombre max de dépôts</label>
                    <input type="number" name="max_submissions" id="max_submissions"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150"
                           value="{{ old('max_submissions') }}"
                           placeholder="Illimité si vide…"
                           min="1">
                </div>

                <div class="flex items-center">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="is_anonymous" id="is_anonymous" value="1"
                               class="sr-only peer"
                               {{ old('is_anonymous') ? 'checked' : '' }}>
                        <div class="w-10 h-6 bg-slate-200 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 rounded-full peer peer-checked:after:translate-x-4 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-600"></div>
                        <span class="ml-3 text-sm font-medium text-slate-700">Activer l'anonymat</span>
                    </label>
                </div>
            </div>
        </section>

        {{-- Section: Champs --}}
        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 shrink-0">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Champs du formulaire</h2>
                        <p class="text-xs text-slate-500">Ajoutez les champs demandés aux étudiants</p>
                    </div>
                </div>
                <button type="button" onclick="addField()"
                        class="inline-flex items-center justify-center px-3.5 py-2 border border-transparent text-sm font-semibold rounded-xl text-brand-700 bg-brand-50 hover:bg-brand-100 focus-visible:ring-2 focus-visible:ring-brand-500 transition-colors duration-150 shrink-0"
                        aria-label="Ajouter un champ">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter un champ
                </button>
            </div>

            <div id="fields-container" class="space-y-4">
                @php $fieldIndex = 0; @endphp
                @include('admin.forms._field-row', ['index' => 0])
            </div>

            <p class="mt-4 text-xs text-slate-400 flex items-center">
                <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Types : texte, email, téléphone, fichier, liste déroulante, case à cocher
            </p>
        </section>

        {{-- Actions --}}
        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ route('admin.forms.index') }}"
               class="px-5 py-2.5 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150 text-center">
                Annuler
            </a>
            <button type="submit"
                    class="px-6 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover transition-all duration-150">
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
        <div class="field-row bg-slate-50/80 rounded-xl border border-slate-200/70 p-4 space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                <div class="flex-1 min-w-0">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Libellé du champ</label>
                    <input type="text" name="field_labels[]" required
                           class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white"
                           placeholder="Ex: Filière…">
                </div>
                <div class="w-full sm:w-40">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Type</label>
                    <select name="field_types[]"
                            class="field-type-select w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white"
                            onchange="toggleOptions(this.closest('.field-row'), this.value)">
                        <option value="text">Texte</option>
                        <option value="email">Email</option>
                        <option value="tel">Téléphone</option>
                        <option value="file">Fichier</option>
                        <option value="select">Liste déroulante</option>
                        <option value="checkbox">Case à cocher</option>
                    </select>
                </div>
                <div class="flex items-center gap-3 sm:mt-6">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="field_requireds[]" value="${index}"
                               class="sr-only peer"
                               checked>
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
            <div class="field-options-wrapper hidden">
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Options de la liste (une par ligne)</label>
                <textarea name="field_options[]" rows="3"
                          class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white"
                          placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
            </div>
        </div>`;
    }
</script>
@endpush
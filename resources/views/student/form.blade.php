<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0347f5">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $form->title }} - Iziwork</title>
    @include('partials.theme')
    <style>

        /* Submit loading state */
        .btn-submit:disabled { opacity: 0.75; cursor: not-allowed; }
        .btn-submit .spinner { display: none; }
        .btn-submit:disabled .spinner { display: inline-block; animation: spin 1s linear infinite; }
        .btn-submit:disabled .btn-text { display: none; }
        .btn-submit:disabled .btn-loading { display: inline; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
</head>
<body class="min-h-screen bg-slate-50 safe-top safe-bottom">
    <div class="min-h-screen flex flex-col">
        {{-- Header --}}
        <header class="gradient-bg text-white safe-top">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <div class="inline-flex items-center bg-white rounded-xl px-3 py-2 mb-5 shadow-sm">
                    <img src="{{ asset('images/iziwork-logo.png') }}"
                         alt="Iziwork"
                         width="1021" height="264"
                         class="h-6 w-auto">
                </div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-balance">{{ $form->title }}</h1>
                @if($form->description)
                <p class="mt-2 text-sm sm:text-base text-white/80">{{ $form->description }}</p>
                @endif
                @if($form->close_date)
                <p class="mt-4 inline-flex items-center gap-1.5 text-xs font-medium bg-white/10 backdrop-blur rounded-lg px-3 py-1.5 text-white/90">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Clôture : <span style="font-variant-numeric: tabular-nums">{{ $form->close_date->format('d/m/Y à H:i') }}</span>
                </p>
                @endif
            </div>
        </header>

        {{-- Form --}}
        <main class="flex-1 py-8 sm:py-10">
            <div class="max-w-2xl mx-auto px-4 sm:px-6">
                @if(session('error'))
                <div class="mb-6 p-4 bg-red-50 border border-red-200/70 text-red-700 rounded-xl text-sm" role="alert" aria-live="assertive">
                    {{ session('error') }}
                </div>
                @endif

                @if($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200/70 text-red-700 rounded-xl text-sm" role="alert" aria-live="assertive">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <form method="POST"
                      action="{{ route('submit.process', $form->token) }}"
                      enctype="multipart/form-data"
                      class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-8"
                      id="submission-form"
                      novalidate>
                    @csrf

                    @foreach($form->fields as $field)
                    <div class="mb-7 last:mb-0">
                        @php
                            $fieldName = $field->getFieldName();
                            $oldValue = old($fieldName);
                            $hasError = $errors->has($fieldName);
                        @endphp

                        <label for="field_{{ $field->id }}" class="block text-sm font-medium text-slate-700 mb-2">
                            {{ $field->field_label }}
                            @if($field->required)
                            <span class="text-red-500" aria-hidden="true">*</span>
                            @endif
                        </label>

                        {{-- TEXT --}}
                        @if($field->field_type === 'text')
                        <input type="text"
                               name="{{ $fieldName }}"
                               id="field_{{ $field->id }}"
                               {{ $field->required ? 'required' : '' }}
                               autocomplete="off"
                               class="w-full px-4 py-3 border rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $hasError ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                               value="{{ $oldValue }}"
                               placeholder="Entrez votre {{ strtolower($field->field_label) }}…">
                        @error($fieldName)
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- EMAIL --}}
                        @elseif($field->field_type === 'email')
                        <input type="email"
                               name="{{ $fieldName }}"
                               id="field_{{ $field->id }}"
                               {{ $field->required ? 'required' : '' }}
                               autocomplete="email"
                               inputmode="email"
                               spellcheck="false"
                               class="w-full px-4 py-3 border rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $hasError ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                               value="{{ $oldValue }}"
                               placeholder="exemple@email.com">
                        @error($fieldName)
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- TEL --}}
                        @elseif($field->field_type === 'tel')
                        <input type="tel"
                               name="{{ $fieldName }}"
                               id="field_{{ $field->id }}"
                               {{ $field->required ? 'required' : '' }}
                               autocomplete="tel"
                               inputmode="tel"
                               class="w-full px-4 py-3 border rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $hasError ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                               value="{{ $oldValue }}"
                               placeholder="+243 XXX XXX XXX">
                        @error($fieldName)
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- SELECT --}}
                        @elseif($field->field_type === 'select')
                        <select name="{{ $fieldName }}"
                                id="field_{{ $field->id }}"
                                {{ $field->required ? 'required' : '' }}
                                class="w-full px-4 py-3 border rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 bg-white {{ $hasError ? 'border-red-300' : 'border-slate-300' }}"
                                style="background-color: white;">
                            <option value="">-- Sélectionnez une option --</option>
                            @foreach($field->getOptionsList() as $option)
                            <option value="{{ $option }}" {{ $oldValue === $option ? 'selected' : '' }}>
                                {{ $option }}
                            </option>
                            @endforeach
                        </select>
                        @error($fieldName)
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- CHECKBOX --}}
                        @elseif($field->field_type === 'checkbox')
                        <label class="flex items-center cursor-pointer select-none">
                            <input type="checkbox"
                                   name="{{ $fieldName }}"
                                   id="field_{{ $field->id }}"
                                   value="1"
                                   {{ $oldValue ? 'checked' : '' }}
                                   class="h-4 w-4 text-brand-600 border-slate-300 rounded focus-visible:ring-2 focus-visible:ring-brand-500/40 transition-colors duration-150">
                            <span class="ml-2.5 text-sm text-slate-700">{{ $field->field_label }}</span>
                        </label>
                        @error($fieldName)
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- FILE --}}
                        @elseif($field->field_type === 'file')
                        <div class="mt-1 flex justify-center px-4 sm:px-6 pt-6 pb-7 border-2 border-dashed rounded-xl hover:border-brand-400 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 transition-colors duration-150 {{ $hasError ? 'border-red-300 bg-red-50/20' : 'border-slate-300' }}">
                            <div class="space-y-2 text-center">
                                <svg class="mx-auto h-10 w-10 sm:h-12 sm:w-12 text-slate-300" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-slate-600 justify-center">
                                    <label for="file_{{ $field->id }}" class="relative cursor-pointer rounded-md font-semibold text-brand-600 hover:text-brand-700 focus-visible:ring-2 focus-visible:ring-brand-500 transition-colors duration-150">
                                        <span>Télécharger un fichier</span>
                                        <input id="file_{{ $field->id }}" name="{{ $fieldName }}[]" type="file"
                                               class="sr-only" multiple
                                               accept=".pdf,.docx,.pptx,.zip">
                                    </label>
                                </div>
                                <p class="text-xs text-slate-400">PDF, Word, PowerPoint, ZIP — max 5 Mo</p>
                            </div>
                        </div>
                        <div id="file-preview-{{ $field->id }}" class="mt-2 text-sm text-slate-600" aria-live="polite"></div>
                        @error($fieldName)
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                        @endif
                    </div>
                    @endforeach

                    {{-- Actions --}}
                    <div class="flex flex-col-reverse sm:flex-row justify-between items-stretch sm:items-center gap-3 pt-6 mt-8 border-t border-slate-100">
                        <button type="button" onclick="window.history.back()"
                                class="px-5 py-3 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150 order-2 sm:order-1">
                            ← Précédent
                        </button>
                        <button type="submit"
                                class="btn-submit px-6 py-3 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover transition-all duration-150 order-1 sm:order-2 flex items-center justify-center gap-2">
                            <span class="btn-text">
                                Je valide mon dépôt
                                <svg class="inline h-4 w-4 ml-1 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </span>
                            <span class="btn-loading hidden">
                                <svg class="spinner h-4 w-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Envoi en cours…
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </main>

        {{-- Footer --}}
        <footer class="py-6 text-center text-xs text-slate-400">
            Propulsé par <span class="font-semibold text-slate-500">Iziwork</span> — Plateforme de dépôt de travaux
        </footer>
    </div>

    <script>
        // File preview
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function(e) {
                const preview = this.closest('.mb-7').querySelector('[id^="file-preview"]');
                const files = Array.from(e.target.files);

                if (files.length > 0) {
                    const html = files.map(f => {
                        const size = (f.size / 1024 / 1024).toFixed(2);
                        return `<div class="flex items-center space-x-2 py-1.5">
                            <svg class="h-4 w-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="truncate min-w-0 text-slate-700">${f.name}</span>
                            <span class="text-slate-400 shrink-0 text-xs">(${size} Mo)</span>
                        </div>`;
                    }).join('');
                    preview.innerHTML = html;
                } else {
                    preview.innerHTML = '';
                }
            });
        });

        // Client-side validation + loading state
        document.getElementById('submission-form').addEventListener('submit', function(e) {
            const files = this.querySelectorAll('input[type="file"]');
            const maxSize = 5 * 1024 * 1024;
            const allowedTypes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                  'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'];

            for (const fileInput of files) {
                for (const file of fileInput.files) {
                    if (file.size > maxSize) {
                        alert(`Le fichier "${file.name}" dépasse la taille maximale de 5 Mo.`);
                        e.preventDefault();
                        return;
                    }
                    if (!allowedTypes.includes(file.type)) {
                        alert(`Le fichier "${file.name}" n'est pas un format accepté.`);
                        e.preventDefault();
                        return;
                    }
                }
            }

            const btn = this.querySelector('.btn-submit');
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        });

        // Warn before leaving with unsaved changes
        let formDirty = false;
        document.getElementById('submission-form').addEventListener('input', () => { formDirty = true; });
        document.getElementById('submission-form').addEventListener('submit', () => { formDirty = false; });
        window.addEventListener('beforeunload', (e) => {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>
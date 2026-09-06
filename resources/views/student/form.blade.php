<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $form->title }} - Iziwork</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
        *:focus-visible {
            outline: 2px solid #667eea;
            outline-offset: 2px;
            border-radius: 4px;
        }
        a, button, input, select, textarea {
            -webkit-tap-highlight-color: rgba(102, 126, 234, 0.15);
        }
        button, input[type="submit"] { touch-action: manipulation; }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }

        /* Submit loading state */
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .btn-submit .spinner {
            display: none;
        }
        .btn-submit:disabled .spinner {
            display: inline-block;
            animation: spin 1s linear infinite;
        }
        .btn-submit:disabled .btn-text {
            display: none;
        }
        .btn-submit:disabled .btn-loading {
            display: inline;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50 safe-top safe-bottom">
    <div class="min-h-screen flex flex-col">
        {{-- Header --}}
        <header class="gradient-bg text-white py-6 safe-top">
            <div class="max-w-3xl mx-auto px-4">
                <h1 class="text-xl sm:text-2xl font-bold text-balance">{{ $form->title }}</h1>
                @if($form->description)
                <p class="mt-2 text-white/80 text-sm sm:text-base">{{ $form->description }}</p>
                @endif
            </div>
        </header>

        {{-- Form --}}
        <main class="flex-1 py-6 sm:py-8">
            <div class="max-w-3xl mx-auto px-4">
                @if(session('error'))
                <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm" role="alert" aria-live="assertive">
                    {{ session('error') }}
                </div>
                @endif

                @if($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm" role="alert" aria-live="assertive">
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
                      class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6"
                      id="submission-form"
                      novalidate>
                    @csrf
                    
                    @foreach($form->fields as $field)
                    <div class="mb-6">
                        @php
                            $fieldName = match(strtolower($field->field_label)) {
                                'nom complet', 'nom', 'name' => 'student_name',
                                'email', 'adresse email' => 'student_email',
                                'téléphone', 'telephone', 'tel', 'phone' => 'student_phone',
                                'filière', 'filiere', 'major', 'spécialité' => 'student_major',
                                default => 'student_' . strtolower(str_replace(' ', '_', $field->field_label)),
                            };
                            $oldValue = old($fieldName);
                            $hasError = $errors->has($fieldName);
                        @endphp

                        <label for="field_{{ $field->id }}" class="block text-sm font-medium text-gray-700 mb-2">
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
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200 @error($fieldName) border-red-300 @enderror"
                               value="{{ $oldValue }}"
                               placeholder="Entrez votre {{ strtolower($field->field_label) }}…">
                        @error($fieldName)
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
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
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200 @error($fieldName) border-red-300 @enderror"
                               value="{{ $oldValue }}"
                               placeholder="exemple@email.com…">
                        @error($fieldName)
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                        
                        {{-- TEL --}}
                        @elseif($field->field_type === 'tel')
                        <input type="tel" 
                               name="{{ $fieldName }}" 
                               id="field_{{ $field->id }}"
                               {{ $field->required ? 'required' : '' }}
                               autocomplete="tel"
                               inputmode="tel"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200 @error($fieldName) border-red-300 @enderror"
                               value="{{ $oldValue }}"
                               placeholder="+243 XXX XXX XXX">
                        @error($fieldName)
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                        
                        {{-- SELECT --}}
                        @elseif($field->field_type === 'select')
                        <select name="{{ $fieldName }}" 
                                id="field_{{ $field->id }}"
                                {{ $field->required ? 'required' : '' }}
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200 @error($fieldName) border-red-300 @enderror"
                                style="background-color: white;">
                            <option value="">-- Sélectionnez une option --</option>
                            @foreach($field->getOptionsList() as $option)
                            <option value="{{ $option }}" {{ $oldValue === $option ? 'selected' : '' }}>
                                {{ $option }}
                            </option>
                            @endforeach
                        </select>
                        @error($fieldName)
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                        
                        {{-- CHECKBOX --}}
                        @elseif($field->field_type === 'checkbox')
                        <div class="flex items-start">
                            <input type="checkbox" 
                                   name="{{ $fieldName }}" 
                                   id="field_{{ $field->id }}"
                                   value="1"
                                   {{ $oldValue ? 'checked' : '' }}
                                   class="mt-0.5 h-4 w-4 text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500 border-gray-300 rounded transition-colors duration-200">
                            <label for="field_{{ $field->id }}" class="ml-2 block text-sm text-gray-700">
                                {{ $field->field_label }}
                            </label>
                        </div>
                        @error($fieldName)
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                        
                        {{-- FILE --}}
                        @elseif($field->field_type === 'file')
                        <div class="mt-1 flex justify-center px-4 sm:px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:border-indigo-400 focus-within:border-indigo-400 focus-within:ring-2 focus-within:ring-indigo-100 transition-colors duration-200">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-10 w-10 sm:h-12 sm:w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 justify-center">
                                    <label for="file_{{ $field->id }}" class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500">
                                        <span>Télécharger un fichier</span>
                                        <input id="file_{{ $field->id }}" name="files[]" type="file" 
                                               class="sr-only" multiple
                                               accept=".pdf,.docx,.pptx,.zip">
                                    </label>
                                </div>
                                <p class="text-xs text-gray-500">PDF, Word, PowerPoint, ZIP (max 5 Mo)</p>
                            </div>
                        </div>
                        <div id="file-preview-{{ $field->id }}" class="mt-2 text-sm text-gray-500" aria-live="polite"></div>
                        @error($fieldName)
                        <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                        @endif
                    </div>
                    @endforeach

                    <div class="flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-3 pt-4 border-t">
                        <button type="button" onclick="window.history.back()" 
                                class="px-4 py-2.5 border border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 transition-colors duration-200 order-2 sm:order-1">
                            ← Précédent
                        </button>
                        <button type="submit" 
                                class="btn-submit px-6 py-2.5 border border-transparent text-sm font-medium rounded-lg text-white gradient-bg hover:opacity-90 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 transition-opacity duration-200 order-1 sm:order-2">
                            <span class="btn-text">Je valide</span>
                            <span class="btn-loading hidden">
                                <svg class="spinner h-4 w-4 inline -mt-0.5" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Envoi…
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <footer class="py-4 text-center text-sm text-gray-500">
            Propulsé par <span class="font-semibold gradient-bg text-transparent bg-clip-text">Iziwork</span>
        </footer>
    </div>

    <script>
        // File preview
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function(e) {
                const preview = this.closest('.mb-6').querySelector('[id^="file-preview"]');
                const files = Array.from(e.target.files);
                
                if (files.length > 0) {
                    const html = files.map(f => {
                        const size = (f.size / 1024 / 1024).toFixed(2);
                        return `<div class="flex items-center space-x-2 py-1">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="truncate min-w-0">${f.name}</span>
                            <span class="text-gray-400 shrink-0">(${size} Mo)</span>
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

            // Show loading state
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

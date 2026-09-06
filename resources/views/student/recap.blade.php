<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <title>Récapitulatif - Iziwork</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        @media (prefers-reduced-motion: reduce) { * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
        *:focus-visible { outline: 2px solid #667eea; outline-offset: 2px; border-radius: 4px; }
        a, button { -webkit-tap-highlight-color: rgba(102, 126, 234, 0.15); touch-action: manipulation; }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="min-h-screen bg-gray-50 safe-top safe-bottom">
    <div class="min-h-screen flex flex-col">
        <header class="gradient-bg text-white py-6 safe-top">
            <div class="max-w-3xl mx-auto px-4">
                <h1 class="text-xl sm:text-2xl font-bold">Récapitulatif de votre dépôt</h1>
                <p class="mt-2 text-white/80 text-sm sm:text-base">{{ $form->title }}</p>
            </div>
        </header>

        <main class="flex-1 py-6 sm:py-8">
            <div class="max-w-3xl mx-auto px-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
                    <div class="flex items-center justify-center mb-6">
                        <div class="p-4 rounded-full bg-green-100">
                            <svg class="h-12 w-12 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    
                    <h2 class="text-lg sm:text-xl font-bold text-center text-gray-900 mb-6">Dépôt validé avec succès !</h2>

                    <div class="space-y-4">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Informations</h3>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                @foreach($form->fields as $field)
                                    @php
                                        $fieldName = match(strtolower($field->field_label)) {
                                            'nom complet', 'nom', 'name' => 'student_name',
                                            'email', 'adresse email' => 'student_email',
                                            'téléphone', 'telephone', 'tel', 'phone' => 'student_phone',
                                            'filière', 'filiere', 'major', 'spécialité' => 'student_major',
                                            default => 'student_' . strtolower(str_replace(' ', '_', $field->field_label)),
                                        };
                                        $value = $submission->$fieldName ?? null;
                                        if ($field->field_type === 'checkbox') {
                                            $value = $value ? 'Oui' : 'Non';
                                        }
                                    @endphp
                                    @if($value)
                                    <div>
                                        <dt class="text-xs sm:text-sm text-gray-500">{{ $field->field_label }}</dt>
                                        <dd class="text-sm font-medium text-gray-900 break-words">{{ $value }}</dd>
                                    </div>
                                    @endif
                                @endforeach
                                <div>
                                    <dt class="text-xs sm:text-sm text-gray-500">Date de soumission</dt>
                                    <dd class="text-sm font-medium text-gray-900" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y à H:i') }}</dd>
                                </div>
                                @if($submission->anonymous_code)
                                <div>
                                    <dt class="text-xs sm:text-sm text-gray-500">Code anonyme</dt>
                                    <dd class="text-sm font-medium text-gray-900">{{ $submission->anonymous_code }}</dd>
                                </div>
                                @endif
                            </dl>
                        </div>

                        @if($submission->files->count() > 0)
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Fichiers déposés ({{ $submission->files->count() }})</h3>
                            <ul class="space-y-2">
                                @foreach($submission->files as $file)
                                <li class="flex items-center justify-between py-2 border-b border-gray-200 last:border-0 gap-3">
                                    <div class="flex items-center min-w-0">
                                        <svg class="h-5 w-5 text-gray-400 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="text-sm text-gray-900 truncate">{{ $file->original_name }}</span>
                                    </div>
                                    <span class="text-sm text-gray-500 shrink-0">{{ $file->formatted_size }}</span>
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    </div>

                    <div class="mt-6 flex justify-center">
                        <a href="{{ route('submission.recap.pdf', ['token' => $form->token, 'submission' => $submission->id]) }}"
                           class="inline-flex items-center px-5 sm:px-6 py-3 border border-transparent text-sm font-medium rounded-lg text-white gradient-bg hover:opacity-90 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 transition-opacity duration-200">
                            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            Télécharger le PDF
                        </a>
                    </div>
                </div>
            </div>
        </main>

        <footer class="py-4 text-center text-sm text-gray-500">
            Propulsé par <span class="font-semibold gradient-bg text-transparent bg-clip-text">Iziwork</span>
        </footer>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <title>Récapitulatif - Iziwork</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        brand: { 50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81' }
                    },
                    boxShadow: {
                        'card': '0 1px 2px 0 rgb(0 0 0 / 0.03), 0 1px 3px 0 rgb(0 0 0 / 0.04)',
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .gradient-bg { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
        @media (prefers-reduced-motion: reduce) { * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
        *:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; border-radius: 4px; }
        a, button { -webkit-tap-highlight-color: rgba(99, 102, 241, 0.12); touch-action: manipulation; }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="min-h-screen bg-slate-50 safe-top safe-bottom">
    <div class="min-h-screen flex flex-col">
        {{-- Header --}}
        <header class="gradient-bg text-white safe-top">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 py-8">
                <div class="inline-flex items-center bg-white rounded-xl px-3 py-2 mb-4 shadow-sm">
                    <img src="{{ asset('images/iziwork-logo.png') }}"
                         alt="Iziwork"
                         width="1021" height="264"
                         class="h-6 w-auto">
                </div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight">Récapitulatif de votre dépôt</h1>
                <p class="mt-1.5 text-sm text-white/80">{{ $form->title }}</p>
            </div>
        </header>

        {{-- Content --}}
        <main class="flex-1 py-8 sm:py-10">
            <div class="max-w-2xl mx-auto px-4 sm:px-6">
                <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
                    {{-- Success icon --}}
                    <div class="flex flex-col items-center mb-8">
                        <div class="h-16 w-16 rounded-full bg-emerald-50 flex items-center justify-center mb-4">
                            <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-900 text-center">Dépôt validé avec succès !</h2>
                        <p class="mt-1.5 text-sm text-slate-500 text-center">Votre travail a bien été transmis à votre administrateur.</p>
                    </div>

                    <div class="space-y-5">
                        {{-- Info card --}}
                        <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-5">
                            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Informations du dépôt</h3>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                                @foreach($form->fields as $field)
                                    @php
                                        $fieldName = $field->getFieldName();
                                        $value = $field->field_type === 'file' ? null : ($submission->$fieldName ?? null);
                                        if ($field->field_type === 'checkbox') {
                                            $value = $value ? 'Oui' : 'Non';
                                        }
                                    @endphp
                                    @if($value)
                                    <div>
                                        <dt class="text-xs font-medium text-slate-500 mb-0.5">{{ $field->field_label }}</dt>
                                        <dd class="text-sm font-semibold text-slate-900 break-words">{{ $value }}</dd>
                                    </div>
                                    @endif
                                @endforeach
                                <div>
                                    <dt class="text-xs font-medium text-slate-500 mb-0.5">Date de soumission</dt>
                                    <dd class="text-sm font-semibold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y à H:i') }}</dd>
                                </div>
                                @if($submission->anonymous_code)
                                <div>
                                    <dt class="text-xs font-medium text-slate-500 mb-0.5">Code anonyme</dt>
                                    <dd class="text-sm font-semibold text-violet-700">{{ $submission->anonymous_code }}</dd>
                                </div>
                                @endif
                            </dl>
                        </div>

                        {{-- Files card --}}
                        @if($submission->files->count() > 0)
                        <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-5">
                            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Fichiers déposés ({{ $submission->files->count() }})</h3>
                            <ul class="space-y-2">
                                @foreach($submission->files as $file)
                                <li class="flex items-center justify-between py-2.5 border-b border-slate-200 last:border-0 gap-3">
                                    <div class="flex items-center min-w-0">
                                        <svg class="h-5 w-5 text-slate-400 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="text-sm text-slate-800 truncate">{{ $file->original_name }}</span>
                                    </div>
                                    <span class="text-xs text-slate-400 shrink-0" style="font-variant-numeric: tabular-nums">{{ $file->formatted_size }}</span>
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    </div>

                    {{-- Download button --}}
                    <div class="mt-8 flex justify-center">
                        <a href="{{ route('submission.recap.pdf', ['token' => $form->token, 'receiptToken' => $submission->receipt_token]) }}"
                           class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover transition-all duration-150">
                            <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            Télécharger le récapitulatif (PDF)
                        </a>
                    </div>
                </div>
            </div>
        </main>

        <footer class="py-6 text-center text-xs text-slate-400">
            Propulsé par <span class="font-semibold text-slate-500">Iziwork</span> — Plateforme de dépôt de travaux
        </footer>
    </div>
</body>
</html>
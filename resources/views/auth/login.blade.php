<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <title>Connexion - Iziwork</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .gradient-bg { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
        @media (prefers-reduced-motion: reduce) { * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
        *:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; border-radius: 4px; }
        a, button, input { -webkit-tap-highlight-color: rgba(99, 102, 241, 0.12); }
        button, input[type="submit"] { touch-action: manipulation; }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 safe-top safe-bottom">
    <div class="w-full max-w-md">
        {{-- Brand --}}
        <div class="text-center mb-8">
            <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl gradient-bg text-white shadow-modal mb-4">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </span>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Iziwork</h1>
            <p class="mt-1.5 text-sm text-slate-500">Gestion de dépôts de travaux étudiants</p>
        </div>

        {{-- Card --}}
        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <h2 class="text-base font-semibold text-slate-900 mb-1">Connexion administrateur</h2>
            <p class="text-sm text-slate-500 mb-6">Accédez à votre espace de gestion</p>

            @if(!empty($noAdminAccount))
            <div class="bg-amber-50 border border-amber-200/70 text-amber-800 px-4 py-3 rounded-xl text-sm mb-5" role="status">
                <p class="font-semibold">Aucun compte administrateur</p>
                <p class="mt-1">{{ $noAdminMessage }}</p>
            </div>
            @endif

            <form class="space-y-5" method="POST" action="{{ route('login') }}" novalidate>
                @csrf

                @if($errors->any())
                <div class="bg-red-50 border border-red-200/70 text-red-700 px-4 py-3 rounded-xl text-sm" role="alert" aria-live="assertive">
                    {{ $errors->first() }}
                </div>
                @endif

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Adresse email</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-4.5L21 21" />
                            </svg>
                        </span>
                        <input id="email"
                               name="email"
                               type="email"
                               required
                               autocomplete="email"
                               inputmode="email"
                               spellcheck="false"
                               class="w-full pl-11 pr-4 py-2.5 border border-slate-300 placeholder-slate-400 text-slate-900 rounded-xl focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 text-sm"
                               placeholder="admin@iziwork.com"
                               value="{{ old('email') }}">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-1.5">Mot de passe</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </span>
                        <input id="password"
                               name="password"
                               type="password"
                               required
                               autocomplete="current-password"
                               class="w-full pl-11 pr-4 py-2.5 border border-slate-300 placeholder-slate-400 text-slate-900 rounded-xl focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 text-sm"
                               placeholder="••••••••">
                    </div>
                </div>

                <button type="submit"
                        class="w-full flex items-center justify-center gap-2 py-2.5 px-4 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand-500 transition-all duration-150">
                    Se connecter
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-slate-400">
            © {{ date('Y') }} Iziwork — Plateforme de dépôt de travaux
        </p>
    </div>
</body>
</html>
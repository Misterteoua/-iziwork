<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <title>Connexion - Iziwork</title>
    @include('partials.theme')
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 safe-top safe-bottom">
    <div class="w-full max-w-md">
        {{-- Brand --}}
        <div class="text-center mb-8">
            <h1 class="mb-3">
                <img src="{{ asset('images/iziwork-logo.png') }}"
                     alt="Iziwork"
                     width="1021" height="264"
                     class="h-12 w-auto mx-auto">
            </h1>
            <p class="text-sm text-slate-500">Gestion de dépôts de travaux étudiants</p>
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
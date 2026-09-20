<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Iziwork') - Gestion de dépôts</title>
    @include('partials.theme')
    @stack('head')
</head>
<body class="bg-slate-50 min-h-screen safe-top safe-bottom">
    {{-- Skip to main content link --}}
    <a href="#main-content" 
       class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-brand-600 focus:text-white focus:rounded-lg focus:text-sm focus:font-medium">
        Aller au contenu principal
    </a>

    @if(session('admin_user'))
    <nav class="bg-white border-b border-slate-200/80 safe-top sticky top-0 z-40 backdrop-blur bg-white/90" role="navigation" aria-label="Menu principal">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center min-w-0">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center shrink-0" aria-label="Iziwork - Retour au tableau de bord">
                        <img src="{{ asset('images/iziwork-logo.png') }}"
                             alt="Iziwork"
                             width="1021" height="264"
                             class="h-8 w-auto">
                    </a>
                    {{-- Desktop nav --}}
                    <div class="hidden sm:ml-8 sm:flex sm:space-x-1" role="menubar">
                        <a href="{{ route('admin.dashboard') }}" 
                           role="menuitem"
                           class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-colors duration-150 {{ request()->routeIs('admin.dashboard') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                            Tableau de bord
                        </a>
                        <a href="{{ route('admin.forms.index') }}" 
                           role="menuitem"
                           class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-colors duration-150 {{ request()->routeIs('admin.forms.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                            Formulaires
                        </a>
                        <a href="{{ route('admin.quizzes.index') }}" 
                           role="menuitem"
                           class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-colors duration-150 {{ request()->routeIs('admin.quizzes.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' }}">
                            Évaluations
                        </a>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.profile') }}"
                       class="flex items-center gap-2.5 pl-3 pr-2 py-1.5 rounded-lg transition-colors duration-150 {{ request()->routeIs('admin.profile') ? 'bg-brand-50' : 'hover:bg-slate-50' }}"
                       aria-label="Mon profil">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-brand-700 text-sm font-semibold">
                            {{ strtoupper(substr(session('admin_user.username'), 0, 1)) }}
                        </span>
                        <span class="hidden md:inline text-sm font-medium text-slate-700">{{ session('admin_user.username') }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" 
                                class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700 px-3 py-2 rounded-lg hover:bg-slate-50 transition-colors duration-150"
                                aria-label="Se déconnecter">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span class="hidden sm:inline">Déconnexion</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Mobile nav --}}
        <div class="sm:hidden border-t border-slate-100" role="menubar">
            <div class="flex">
                <a href="{{ route('admin.dashboard') }}" 
                   role="menuitem"
                   class="flex-1 text-center py-3 text-sm font-medium border-b-2 transition-colors duration-150 {{ request()->routeIs('admin.dashboard') ? 'border-brand-600 text-brand-700 bg-brand-50/50' : 'border-transparent text-slate-500' }}">
                    Tableau de bord
                </a>
                <a href="{{ route('admin.forms.index') }}" 
                   role="menuitem"
                   class="flex-1 text-center py-3 text-sm font-medium border-b-2 transition-colors duration-150 {{ request()->routeIs('admin.forms.*') ? 'border-brand-600 text-brand-700 bg-brand-50/50' : 'border-transparent text-slate-500' }}">
                    Formulaires
                </a>
                <a href="{{ route('admin.quizzes.index') }}" 
                   role="menuitem"
                   class="flex-1 text-center py-3 text-sm font-medium border-b-2 transition-colors duration-150 {{ request()->routeIs('admin.quizzes.*') ? 'border-brand-600 text-brand-700 bg-brand-50/50' : 'border-transparent text-slate-500' }}">
                    Évaluations
                </a>
                <a href="{{ route('admin.profile') }}" 
                   role="menuitem"
                   class="flex-1 text-center py-3 text-sm font-medium border-b-2 transition-colors duration-150 {{ request()->routeIs('admin.profile') ? 'border-brand-600 text-brand-700 bg-brand-50/50' : 'border-transparent text-slate-500' }}">
                    Profil
                </a>
            </div>
        </div>
    </nav>
    @endif

    <main id="main-content" class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8" tabindex="-1">
        {{-- Flash messages with aria-live --}}
        @if(session('success'))
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200/70 text-emerald-800 rounded-xl text-sm shadow-card" role="alert" aria-live="polite">
            <div class="flex items-center">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 mr-3 shrink-0">
                    <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </span>
                <span class="font-medium">{{ session('success') }}</span>
            </div>
        </div>
        @endif

        @if(session('error'))
        <div class="mb-6 p-4 bg-red-50 border border-red-200/70 text-red-800 rounded-xl text-sm shadow-card" role="alert" aria-live="assertive">
            <div class="flex items-center">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-red-100 mr-3 shrink-0">
                    <svg class="h-4 w-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                <span class="font-medium">{{ session('error') }}</span>
            </div>
        </div>
        @endif

        @yield('content')
    </main>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    </script>

    @include('partials.copy')

    @stack('scripts')

    @if(session('admin_user'))
    <footer class="border-t border-slate-100 py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <p class="text-xs text-slate-400 text-center">
                Iziwork v{{ config('app.version', 'dev') }} · Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}
            </p>
        </div>
    </footer>
    @endif
</body>
</html>
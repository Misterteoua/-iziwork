<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Iziwork') - Gestion de dépôts</title>
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
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            300: '#a5b4fc',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        }
                    },
                    boxShadow: {
                        'card': '0 1px 2px 0 rgb(0 0 0 / 0.03), 0 1px 3px 0 rgb(0 0 0 / 0.04)',
                        'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.04)',
                        'modal': '0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.05)',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        }

        .gradient-text {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* Safe area insets for notched devices */
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
        .safe-left { padding-left: env(safe-area-inset-left); }
        .safe-right { padding-right: env(safe-area-inset-right); }

        /* Focus visible ring for all interactive elements */
        *:focus-visible {
            outline: 2px solid #6366f1;
            outline-offset: 2px;
            border-radius: 4px;
        }

        /* Tap highlight */
        a, button, input, select, textarea {
            -webkit-tap-highlight-color: rgba(99, 102, 241, 0.12);
        }

        /* Touch action for interactive elements */
        button, a, input[type="submit"] {
            touch-action: manipulation;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
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
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 shrink-0" aria-label="Iziwork - Retour au tableau de bord">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg gradient-bg text-white">
                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </span>
                        <span class="text-lg font-bold tracking-tight text-slate-900">Iziwork</span>
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
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="hidden md:flex items-center gap-2.5 pl-3">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-brand-700 text-sm font-semibold">
                            {{ strtoupper(substr(session('admin_user.username'), 0, 1)) }}
                        </span>
                        <span class="text-sm font-medium text-slate-700">{{ session('admin_user.username') }}</span>
                    </div>
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
    @stack('scripts')
</body>
</html>
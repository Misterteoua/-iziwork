<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Iziwork') - Gestion de dépôts</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            500: '#667eea',
                            600: '#5a6fd6',
                            700: '#4e5db8',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            outline: 2px solid #667eea;
            outline-offset: 2px;
            border-radius: 4px;
        }

        /* Tap highlight */
        a, button, input, select, textarea {
            -webkit-tap-highlight-color: rgba(102, 126, 234, 0.15);
        }

        /* Touch action for interactive elements */
        button, a, input[type="submit"] {
            touch-action: manipulation;
        }
    </style>
    @stack('head')
</head>
<body class="bg-gray-50 min-h-screen safe-top safe-bottom">
    {{-- Skip to main content link --}}
    <a href="#main-content" 
       class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-indigo-600 focus:text-white focus:rounded-lg focus:text-sm focus:font-medium">
        Aller au contenu principal
    </a>

    @if(session('admin_user'))
    <nav class="bg-white shadow-sm border-b safe-top" role="navigation" aria-label="Menu principal">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center min-w-0">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center shrink-0" aria-label="Iziwork - Retour au tableau de bord">
                        <span class="text-2xl font-bold gradient-bg text-transparent bg-clip-text">Iziwork</span>
                    </a>
                    {{-- Desktop nav --}}
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8" role="menubar">
                        <a href="{{ route('admin.dashboard') }}" 
                           role="menuitem"
                           class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('admin.dashboard') ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} text-sm font-medium transition-colors duration-200">
                            Tableau de bord
                        </a>
                        <a href="{{ route('admin.forms.index') }}" 
                           role="menuitem"
                           class="inline-flex items-center px-1 pt-1 border-b-2 {{ request()->routeIs('admin.forms.*') ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} text-sm font-medium transition-colors duration-200">
                            Formulaires
                        </a>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden sm:inline text-sm text-gray-500">{{ session('admin_user.username') }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" 
                                class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors duration-200"
                                aria-label="Se déconnecter">
                            Déconnexion
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Mobile nav --}}
        <div class="sm:hidden border-t border-gray-100" role="menubar">
            <div class="flex">
                <a href="{{ route('admin.dashboard') }}" 
                   role="menuitem"
                   class="flex-1 text-center py-3 text-sm font-medium border-b-2 {{ request()->routeIs('admin.dashboard') ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500' }}">
                    Tableau de bord
                </a>
                <a href="{{ route('admin.forms.index') }}" 
                   role="menuitem"
                   class="flex-1 text-center py-3 text-sm font-medium border-b-2 {{ request()->routeIs('admin.forms.*') ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500' }}">
                    Formulaires
                </a>
            </div>
        </div>
    </nav>
    @endif

    <main id="main-content" class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8" tabindex="-1">
        {{-- Flash messages with aria-live --}}
        @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm" role="alert" aria-live="polite">
            <div class="flex items-center">
                <svg class="h-5 w-5 mr-2 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ session('success') }}
            </div>
        </div>
        @endif

        @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm" role="alert" aria-live="assertive">
            <div class="flex items-center">
                <svg class="h-5 w-5 mr-2 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ session('error') }}
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

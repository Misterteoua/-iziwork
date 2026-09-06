<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#667eea">
    <meta name="color-scheme" content="light">
    <title>Connexion - Iziwork</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }
        *:focus-visible {
            outline: 2px solid #fff;
            outline-offset: 2px;
            border-radius: 4px;
        }
        a, button, input, select, textarea {
            -webkit-tap-highlight-color: rgba(255, 255, 255, 0.2);
        }
        button, input[type="submit"] { touch-action: manipulation; }
    </style>
</head>
<body class="min-h-screen gradient-bg flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 safe-top safe-bottom">
    <div class="max-w-md w-full space-y-8">
        <div>
            <h1 class="text-center text-3xl sm:text-4xl font-extrabold text-white">Iziwork</h1>
            <h2 class="mt-2 text-center text-lg sm:text-xl text-white/80">Gestion de dépôts de travaux</h2>
            <p class="mt-2 text-center text-sm text-white/60">Connectez-vous à votre compte administrateur</p>
        </div>
        
        <form class="mt-8 space-y-6 bg-white p-6 sm:p-8 rounded-xl shadow-2xl" 
              method="POST" 
              action="{{ route('login') }}"
              novalidate>
            @csrf
            
            @if($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg text-sm" role="alert" aria-live="assertive">
                {{ $errors->first() }}
            </div>
            @endif
            
            <div class="space-y-4">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Adresse email</label>
                    <input id="email" 
                           name="email" 
                           type="email" 
                           required 
                           autocomplete="email"
                           inputmode="email"
                           spellcheck="false"
                           class="mt-1 block w-full px-3 py-3 border border-gray-300 placeholder-gray-400 text-gray-900 rounded-lg focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                           placeholder="admin@iziwork.com…"
                           value="{{ old('email') }}">
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Mot de passe</label>
                    <input id="password" 
                           name="password" 
                           type="password" 
                           required
                           autocomplete="current-password"
                           class="mt-1 block w-full px-3 py-3 border border-gray-300 placeholder-gray-400 text-gray-900 rounded-lg focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:border-indigo-500 transition-colors duration-200"
                           placeholder="••••••••">
                </div>
            </div>

            <button type="submit" 
                    class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white gradient-bg hover:opacity-90 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 transition-opacity duration-200">
                Se connecter
            </button>
        </form>
    </div>
</body>
</html>

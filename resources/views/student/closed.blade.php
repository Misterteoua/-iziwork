<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>Formulaire fermé - Iziwork</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        @media (prefers-reduced-motion: reduce) { * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center safe-top safe-bottom">
    <div class="max-w-md w-full px-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center">
            <div class="mx-auto p-4 rounded-full bg-red-100 w-fit mb-6">
                <svg class="h-10 w-10 sm:h-12 sm:w-12 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            
            <h1 class="text-lg sm:text-xl font-bold text-gray-900 mb-2">Formulaire fermé</h1>
            <p class="text-sm sm:text-base text-gray-500 mb-6">{{ $reason }}</p>
            
            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <h2 class="font-semibold text-gray-900 break-words">{{ $form->title }}</h2>
                @if($form->close_date)
                <p class="text-sm text-gray-500 mt-1" style="font-variant-numeric: tabular-nums">
                    Date de fermeture : {{ $form->close_date->format('d/m/Y à H:i') }}
                </p>
                @endif
            </div>
            
            <p class="text-sm text-gray-500">
                Si vous pensez qu'il s'agit d'une erreur, contactez votre administrateur.
            </p>
        </div>
        
        <div class="mt-6 text-center text-sm text-gray-500">
            Propulsé par <span class="font-semibold gradient-bg text-transparent bg-clip-text">Iziwork</span>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>Formulaire fermé - Iziwork</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
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
        .safe-top { padding-top: env(safe-area-inset-top); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center safe-top safe-bottom">
    <div class="max-w-md w-full px-4">
        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 text-center">
            <div class="mx-auto h-16 w-16 rounded-full bg-amber-50 flex items-center justify-center mb-5">
                <svg class="h-8 w-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <h1 class="text-lg sm:text-xl font-bold tracking-tight text-slate-900 mb-2">Formulaire fermé</h1>
            <p class="text-sm sm:text-base text-slate-500 mb-6">{{ $reason }}</p>

            <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-5 mb-6">
                <h2 class="font-semibold text-slate-900 text-sm break-words">{{ $form->title }}</h2>
                @if($form->close_date)
                <p class="text-xs text-slate-500 mt-1.5" style="font-variant-numeric: tabular-nums">
                    Date de fermeture : {{ $form->close_date->format('d/m/Y à H:i') }}
                </p>
                @endif
            </div>

            <p class="text-sm text-slate-500">
                Si vous pensez qu'il s'agit d'une erreur, contactez votre administrateur.
            </p>
        </div>

        <div class="mt-6 flex flex-col items-center gap-2 text-xs text-slate-400">
            <img src="{{ asset('images/iziwork-logo.png') }}"
                 alt="Iziwork"
                 width="1021" height="264"
                 class="h-6 w-auto opacity-70">
            <span>Propulsé par <span class="font-semibold text-slate-500">Iziwork</span></span>
        </div>
    </div>
</body>
</html>
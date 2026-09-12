<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation terminée - Iziwork</title>
    @include('partials.theme')
</head>
<body class="min-h-screen bg-slate-50 py-10 px-4 sm:px-6 lg:px-8 safe-top safe-bottom">
<div class="w-full max-w-2xl mx-auto">

    <div class="text-center mb-8">
        <h1 class="mb-3">
            <img src="{{ asset('images/iziwork-logo.png') }}"
                 alt="Iziwork"
                 width="1021" height="264"
                 class="h-12 w-auto mx-auto">
        </h1>
        <p class="text-sm text-slate-500">Installation terminée</p>
    </div>

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">

        <div class="flex items-start gap-3 bg-emerald-50 border border-emerald-200/70 text-emerald-800 px-4 py-3 rounded-xl text-sm mb-6"
             role="status">
            <svg class="h-5 w-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
                <p class="font-semibold">L'application est installée.</p>
                <p class="mt-1">
                    Le fichier <code>.env</code> a été écrit, les tables créées et le
                    compte administrateur enregistré.
                </p>
            </div>
        </div>

        <dl class="text-sm divide-y divide-slate-100 border-y border-slate-100">
            <div class="flex justify-between gap-4 py-3">
                <dt class="text-slate-500">Compte administrateur</dt>
                <dd class="font-medium text-slate-900 text-right break-all">{{ $adminEmail }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3">
                <dt class="text-slate-500">Adresse du site</dt>
                <dd class="font-medium text-slate-900 text-right break-all">{{ $appUrl }}</dd>
            </div>
        </dl>

        <div class="mt-6">
            <a href="{{ $appUrl }}/login"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl gradient-bg text-white text-sm font-semibold shadow-card hover:shadow-card-hover transition-shadow duration-150">
                Se connecter
            </a>
        </div>

        <div class="mt-8 pt-6 border-t border-slate-100">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">À faire maintenant</h2>
            <ol class="space-y-2.5 text-sm text-slate-600 list-decimal list-inside">
                <li>
                    Connectez-vous et <strong>changez immédiatement le mot de passe</strong>
                    de l'administrateur.
                </li>
                <li>
                    Activez HTTPS : cPanel → <strong>SSL/TLS Status</strong> → <em>Run AutoSSL</em>.
                </li>
                <li>
                    Vérifiez que <code>{{ $appUrl }}/up</code> répond bien <code>200</code>.
                </li>
                <li>
                    Sauvegardez la base MySQL et le dossier <code>storage/app/private</code>
                    (les dépôts des étudiants y sont stockés).
                </li>
            </ol>
        </div>

        <div class="mt-6 rounded-xl bg-slate-50 border border-slate-200/70 px-4 py-3 text-xs text-slate-500">
            <p>
                Cette page est désormais verrouillée : elle ne peut plus être rejouée
                tant que <code>storage/app/install.lock</code> existe.
            </p>
            <p class="mt-2">
                Pour retirer complètement l'assistant du serveur, supprimez
                <code>app/Http/Controllers/InstallController.php</code>,
                <code>routes/install.php</code>, le dossier
                <code>resources/views/install/</code>, ainsi que le bloc
                <code>then:</code> de <code>bootstrap/app.php</code>.
            </p>
        </div>

        @if($migrations !== '' || $seeding !== '')
        <details class="mt-6 text-xs text-slate-500">
            <summary class="cursor-pointer font-medium text-slate-600">Détail de l'exécution</summary>
            <pre class="mt-3 bg-slate-900 text-slate-100 rounded-xl p-4 overflow-x-auto whitespace-pre-wrap">{{ trim($migrations."\n".$seeding) }}</pre>
        </details>
        @endif
    </div>
</div>
</body>
</html>

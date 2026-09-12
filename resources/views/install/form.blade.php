<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation - Iziwork</title>
    @include('partials.theme')
</head>
<body class="min-h-screen bg-slate-50 py-10 px-4 sm:px-6 lg:px-8 safe-top safe-bottom">
@php
    $inputClass = 'w-full px-3.5 py-2.5 border border-slate-300 placeholder-slate-400 text-slate-900 rounded-xl '
        .'focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 '
        .'transition-colors duration-150 text-sm';
    $labelClass = 'block text-sm font-medium text-slate-700 mb-1.5';
    $hintClass = 'mt-1.5 text-xs text-slate-500';
@endphp

<div class="w-full max-w-3xl mx-auto">

    {{-- Brand --}}
    <div class="text-center mb-8">
        <h1 class="mb-3">
            <img src="{{ asset('images/iziwork-logo.png') }}"
                 alt="Iziwork"
                 width="1021" height="264"
                 class="h-12 w-auto mx-auto">
        </h1>
        <p class="text-sm text-slate-500">Installation de l'application</p>
    </div>

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">

        <h2 class="text-base font-semibold text-slate-900 mb-1">Configuration initiale</h2>
        <p class="text-sm text-slate-500 mb-6">
            Renseignez la base de données MySQL créée dans cPanel, puis le compte
            administrateur. L'application écrira elle-même le fichier
            <code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded">.env</code>,
            créera les tables et le compte admin.
        </p>

        @if(!empty($installErrors))
        <div class="bg-red-50 border border-red-200/70 text-red-700 px-4 py-3 rounded-xl text-sm mb-6"
             role="alert" aria-live="assertive">
            <ul class="space-y-1.5 list-disc list-inside">
                @foreach($installErrors as $message)
                <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if(!$tokenFileExists)
        {{-- Sans jeton, on n'affiche même pas le formulaire. --}}
        <div class="bg-amber-50 border border-amber-200/70 text-amber-900 px-4 py-4 rounded-xl text-sm"
             role="status">
            <p class="font-semibold mb-2">Jeton de sécurité manquant</p>
            <p>
                L'installation est bloquée tant que ce fichier n'existe pas :
            </p>
            <p class="mt-2 font-mono text-xs break-all bg-white/70 border border-amber-200 rounded-lg px-3 py-2">
                {{ $tokenPath }}
            </p>
            <ol class="mt-3 space-y-1.5 list-decimal list-inside">
                <li>Ouvrez le <strong>Gestionnaire de fichiers</strong> de cPanel, à la racine du projet
                    (le dossier qui contient <code>artisan</code>).</li>
                <li>Créez un fichier nommé <code>.install-token</code>.</li>
                <li>Collez-y une longue chaîne aléatoire, par exemple
                    40 caractères de lettres et de chiffres.</li>
                <li>Enregistrez, puis rechargez cette page.</li>
            </ol>
            <p class="mt-3">
                Ce fichier se trouve <strong>hors de <code>public/</code></strong> : il n'est
                donc jamais servi par le web. Il sera supprimé automatiquement
                une fois l'installation réussie.
            </p>
        </div>
        @else
        <form class="space-y-8" method="POST" action="{{ route('install.store') }}" novalidate>

            {{-- Jeton --}}
            <div>
                <label for="install_token" class="{{ $labelClass }}">
                    Jeton d'installation (contenu de <code class="text-xs">.install-token</code>)
                </label>
                <input id="install_token"
                       name="install_token"
                       type="text"
                       required
                       autocomplete="off"
                       spellcheck="false"
                       class="{{ $inputClass }} font-mono"
                       placeholder="collez ici le contenu du fichier">
            </div>

            {{-- Site --}}
            <fieldset>
                <legend class="text-sm font-semibold text-slate-900 mb-4">Adresse du site</legend>
                <div>
                    <label for="app_url" class="{{ $labelClass }}">URL publique</label>
                    <input id="app_url" name="app_url" type="text" required
                           autocomplete="url" spellcheck="false"
                           class="{{ $inputClass }}"
                           value="{{ $values['app_url'] }}"
                           placeholder="https://iziwork.exemple.ci">
                    <p class="{{ $hintClass }}">Le schéma <code>https://</code> est ajouté s'il manque.</p>
                </div>
            </fieldset>

            {{-- Base --}}
            <fieldset>
                <legend class="text-sm font-semibold text-slate-900 mb-4">Base de données MySQL</legend>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label for="db_host" class="{{ $labelClass }}">Hôte</label>
                        <input id="db_host" name="db_host" type="text" required
                               spellcheck="false" class="{{ $inputClass }}"
                               value="{{ $values['db_host'] }}">
                    </div>
                    <div>
                        <label for="db_port" class="{{ $labelClass }}">Port</label>
                        <input id="db_port" name="db_port" type="text" required
                               inputmode="numeric" class="{{ $inputClass }}"
                               value="{{ $values['db_port'] }}">
                    </div>
                </div>

                <div class="mt-4">
                    <label for="db_database" class="{{ $labelClass }}">Nom de la base</label>
                    <input id="db_database" name="db_database" type="text" required
                           spellcheck="false" class="{{ $inputClass }} font-mono"
                           value="{{ $values['db_database'] }}">
                    <p class="{{ $hintClass }}">Tel qu'affiché dans cPanel → Bases de données MySQL,
                        préfixe de compte compris.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label for="db_username" class="{{ $labelClass }}">Utilisateur MySQL</label>
                        <input id="db_username" name="db_username" type="text" required
                               spellcheck="false" class="{{ $inputClass }} font-mono"
                               value="{{ $values['db_username'] }}">
                    </div>
                    <div>
                        <label for="db_password" class="{{ $labelClass }}">Mot de passe MySQL</label>
                        <input id="db_password" name="db_password" type="password"
                               autocomplete="new-password" class="{{ $inputClass }}"
                               value="">
                    </div>
                </div>
                <p class="{{ $hintClass }}">
                    Évitez les espaces en début ou fin de mot de passe : ils sont retirés
                    automatiquement et la connexion échouerait ensuite.
                </p>
            </fieldset>

            {{-- Admin --}}
            <fieldset>
                <legend class="text-sm font-semibold text-slate-900 mb-4">Compte administrateur</legend>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="admin_username" class="{{ $labelClass }}">Nom d'utilisateur</label>
                        <input id="admin_username" name="admin_username" type="text" required
                               autocomplete="username" spellcheck="false" class="{{ $inputClass }}"
                               value="{{ $values['admin_username'] }}">
                    </div>
                    <div>
                        <label for="admin_email" class="{{ $labelClass }}">Adresse email</label>
                        <input id="admin_email" name="admin_email" type="email" required
                               autocomplete="email" spellcheck="false" class="{{ $inputClass }}"
                               value="{{ $values['admin_email'] }}">
                    </div>
                </div>

                <div class="mt-4">
                    <label for="admin_password" class="{{ $labelClass }}">Mot de passe (12 caractères minimum)</label>
                    <input id="admin_password" name="admin_password" type="password" required
                           minlength="12" autocomplete="new-password" class="{{ $inputClass }}"
                           placeholder="un mot de passe fort, à changer après la 1re connexion">
                </div>
            </fieldset>

            <div class="flex flex-col sm:flex-row sm:items-center gap-4 pt-2 border-t border-slate-100">
                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl gradient-bg text-white text-sm font-semibold shadow-card hover:shadow-card-hover transition-shadow duration-150">
                    Lancer l'installation
                </button>
                <p class="text-xs text-slate-500">
                    Les tables seront créées et le compte admin enregistré.
                    La page se désactivera automatiquement ensuite.
                </p>
            </div>
        </form>
        @endif
    </div>

    @if($tokenFileExists)
    <p class="text-xs text-slate-400 text-center mt-6">
        Une fois l'installation terminée, cette page renverra une erreur 404.
    </p>
    @endif
</div>
</body>
</html>

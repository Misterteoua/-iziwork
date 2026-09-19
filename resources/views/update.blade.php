<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mise à jour — Iziwork</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .card { background: white; border-radius: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; max-width: 32rem; width: 100%; }
        h1 { font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem; }
        .info { background: #f1f5f9; border-radius: .5rem; padding: 1rem; margin-bottom: 1.5rem; font-size: .875rem; line-height: 1.6; }
        .info strong { font-weight: 600; }
        .pending { background: #fef3c7; border: 1px solid #fcd34d; border-radius: .5rem; padding: 1rem; margin-bottom: 1.5rem; }
        .info-block { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: .5rem; padding: 1rem; margin-bottom: 1.5rem; }
        .info-block h2 { font-size: .875rem; font-weight: 600; color: #1e40af; margin-bottom: .5rem; }
        .info-block ul { margin: 0; padding-left: 1.25rem; font-size: .8125rem; color: #1e3a8a; }
        .pending h2 { font-size: .875rem; font-weight: 600; color: #92400e; margin-bottom: .5rem; }
        .pending ul { margin: 0; padding-left: 1.25rem; font-size: .8125rem; color: #78350f; }
        .btn { display: inline-flex; align-items: center; gap: .5rem; background: #2563eb; color: white; border: none; border-radius: .5rem; padding: .75rem 1.5rem; font-size: .875rem; font-weight: 600; cursor: pointer; transition: background .15s; }
        .btn:hover { background: #1d4ed8; }
        .btn:disabled { opacity: .6; cursor: not-allowed; }
        .success { background: #dcfce7; border: 1px solid #86efac; border-radius: .5rem; padding: 1rem; margin-bottom: 1.5rem; }
        .success h2 { font-size: .875rem; font-weight: 600; color: #166534; margin-bottom: .5rem; }
        .success ul { margin: 0; padding-left: 1.25rem; font-size: .8125rem; color: #14532d; }
        .error { background: #fef2f2; border: 1px solid #fca5a5; border-radius: .5rem; padding: 1rem; margin-bottom: 1.5rem; }
        .error h2 { font-size: .875rem; font-weight: 600; color: #991b1b; margin-bottom: .5rem; }
        .error ul { margin: 0; padding-left: 1.25rem; font-size: .8125rem; color: #7f1d1d; }
        .token-box { background: #f1f5f9; border: 1px dashed #cbd5e1; border-radius: .5rem; padding: 1rem; margin-top: 1.5rem; font-size: .8125rem; }
        .token-box code { font-family: monospace; background: white; padding: .125rem .375rem; border-radius: .25rem; word-break: break-all; }
        .footer { margin-top: 1.5rem; font-size: .75rem; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔧 Mise à jour Iziwork</h1>

        <div class="info">
            <strong>PHP :</strong> {{ $php }}<br>
            <strong>Laravel :</strong> {{ $laravel }}<br>
            <strong>Base :</strong> {{ $dbOk ? 'connectée ✅' : 'injoignable ❌' }}
        </div>

        @if(isset($done) && $done)
            {{-- Résultat de la mise à jour --}}
            @if(!empty($results))
            <div class="success">
                <h2>✅ Mise à jour appliquée</h2>
                <ul>
                    @foreach($results as $label => $message)
                    <li><strong>{{ ucfirst($label) }} :</strong> {{ $message }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if(!empty($errors))
            <div class="error">
                <h2>⚠️ Erreurs</h2>
                <ul>
                    @foreach($errors as $label => $message)
                    <li><strong>{{ ucfirst($label) }} :</strong> {{ $message }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if($newToken)
            <div class="token-box">
                Nouveau jeton de mise à jour (conservez-le pour la prochaine mise à jour) :<br>
                <code>{{ $newToken }}</code>
            </div>
            @endif

        @elseif((!empty($pending) || !empty($alreadyInPlace)) && $dbOk)
            {{-- Migrations réellement à jouer --}}
            @if(!empty($pending))
            <div class="pending">
                <h2>📋 {{ count($pending) }} migration(s) à jouer</h2>
                <ul>
                    @foreach($pending as $migration)
                    <li>{{ basename($migration, '.php') }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Migrations dont l'effet est déjà présent en base : elles seront
                 simplement enregistrées, sans rien rejouer. --}}
            @if(!empty($alreadyInPlace))
            <div class="info-block">
                <h2>ℹ️ {{ count($alreadyInPlace) }} migration(s) déjà en place dans la base</h2>
                <p style="font-size:.8125rem;color:#1e3a8a;margin-bottom:.5rem;">
                    Le schéma existe déjà (installation via setup.sql). Ces migrations seront
                    enregistrées comme appliquées : aucune table n'est recréée.
                </p>
                <ul>
                    @foreach($alreadyInPlace as $migration)
                    <li>{{ basename($migration, '.php') }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="{{ route('update.run', ['token' => $token]) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="btn" onclick="this.disabled=true;this.textContent='Mise à jour en cours…'">
                    ▶ Lancer la mise à jour
                </button>
            </form>

        @elseif(!$dbOk)
            <div class="error">
                <h2>❌ Base de données injoignable</h2>
                <p style="font-size:.875rem;">Vérifiez que .env contient les bons identifiants MySQL.</p>
            </div>

        @else
            <div class="success">
                <h2>✅ Tout est à jour</h2>
                <p style="font-size:.875rem;">Aucune migration à jouer. Le code et la base sont synchronisés.</p>
            </div>

            <form method="POST" action="{{ route('update.run', ['token' => $token]) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="btn" onclick="this.disabled=true;this.textContent='Mise à jour en cours…'">
                    ▶ Vider les caches
                </button>
            </form>
        @endif

        <div class="footer">
            Iziwork — Assistant de mise à jour
        </div>
    </div>
</body>
</html>

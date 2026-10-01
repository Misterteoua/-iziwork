{{--
    Page d'erreur 500, en français.

    Volontairement autonome : aucun gabarit, aucune feuille de style externe,
    aucune variable. Une page d'erreur s'affiche précisément le jour où quelque
    chose est cassé — elle ne peut donc pas dépendre de ce qui vient de céder.

    En production (APP_DEBUG=false), Laravel l'utilise pour toute erreur 500,
    y compris une écriture refusée par la base ; en local (APP_DEBUG=true), la
    page de diagnostic détaillée reste seule affichée, cette vue n'empêche donc
    rien.

    Rien de technique n'est montré : ni message d'exception, ni chemin, ni
    requête. Le détail est dans storage/logs/laravel.log, pour l'administrateur.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Erreur inattendue — Iziwork</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #f8fafc;
            color: #0f172a;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.55;
        }
        main {
            width: 100%;
            max-width: 34rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 2rem 1.75rem;
            box-shadow: 0 1px 3px rgb(15 23 42 / 8%);
        }
        .code {
            margin: 0;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #64748b;
        }
        h1 { margin: .75rem 0 0; font-size: 1.375rem; line-height: 1.3; }
        p { margin: .75rem 0 0; font-size: .9375rem; color: #334155; }
        .advice {
            margin-top: 1.25rem;
            padding: .75rem 1rem;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: .75rem;
            font-size: .875rem;
        }
        a {
            display: inline-block;
            margin-top: 1.5rem;
            padding: .625rem 1rem;
            background: #1d4ed8;
            color: #fff;
            border-radius: .75rem;
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>
<body>
<main>
    <p class="code">Erreur 500</p>
    <h1>Une erreur inattendue est survenue</h1>

    <p>
        L'incident a été enregistré automatiquement, avec son détail, pour être
        corrigé.
    </p>

    <p class="advice">
        Réessayez dans un instant : la plupart de ces erreurs sont passagères.
        Si celle-ci se reproduit, signalez-la à l'administrateur en indiquant
        l'heure et la page concernée — c'est ce qui permet de la retrouver dans
        le journal.
    </p>

    <a href="/">Revenir à l'accueil</a>
</main>
</body>
</html>

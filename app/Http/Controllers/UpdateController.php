<?php

namespace App\Http\Controllers;

use App\Support\MigrationReconciliation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Assistant de mise à jour web.
 *
 * Permet de relancer les migrations et de vider les caches sans repasser
 * par l'assistant d'installation complet. Utile quand on extrait une nouvelle
 * archive par-dessus une installation existante : le .env et APP_KEY sont
 * conservés, seules les nouvelles migrations sont jouées.
 *
 * Sécurité :
 *   1. le jeton lu dans `.update-token` (hors public/) ;
 *   2. le jeton est tourné après chaque mise à jour réussie.
 *
 * Le contrôleur est chargé hors du groupe « web » (routes/install.php) :
 * il fonctionne même sans session, cookie chiffré ou jeton CSRF.
 */
class UpdateController extends Controller
{
    private const TOKEN_PATH = '.update-token';

    /**
     * Affiche l'état de la mise à jour (migrations en attente, version…).
     */
    public function show(Request $request): View
    {
        $this->abortIfNoEnv();
        $this->abortIfNoToken();
        $provided = $this->validatedToken($request);

        $reconciliation = app(MigrationReconciliation::class);

        // La liste affichée est celle des migrations réellement à jouer :
        // celles dont l'effet est déjà en base (schéma créé par setup.sql)
        // sont présentées à part, comme « déjà en place ».
        $pending = $reconciliation->pending();
        $alreadyInPlace = $reconciliation->satisfiable();

        return view('update', [
            'token' => $provided,
            'pending' => $pending,
            'alreadyInPlace' => $alreadyInPlace,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'envExists' => true,
            'dbOk' => $this->dbReachable(),
        ]);
    }

    /**
     * Lance la mise à jour : migrations + nettoyage des caches.
     */
    public function run(Request $request): View
    {
        $this->abortIfNoEnv();
        $this->abortIfNoToken();
        $this->validatedToken($request);

        if (! $this->dbReachable()) {
            return back()->with('error', 'Connexion à la base de données impossible.');
        }

        $reconciliation = app(MigrationReconciliation::class);

        $results = [];
        $errors = [];

        // 0. Réconcilier la table `migrations` avec le schéma réellement
        //    présent (base créée par setup.sql, import externe…).
        try {
            $synced = $reconciliation->reconcile();

            if ($synced > 0) {
                $results['synchronisation'] = $synced.' migration(s) déjà présente(s) dans la base ont été marquées comme appliquées.';
            }
        } catch (Throwable $e) {
            $errors['synchronisation'] = $e->getMessage();
        }

        // 1. Migrations réellement en attente.
        try {
            Artisan::call('migrate', ['--force' => true]);

            $results['migrations'] = trim(Artisan::output()) ?: 'Aucune migration à jouer.';

            // Une migration qui vient d'échouer parce que son effet existait
            // déjà (colonne ajoutée à la main, par exemple) est enregistrée
            // ici, plutôt que de rester « en attente » pour toujours.
            $late = $reconciliation->reconcile();

            if ($late > 0) {
                $results['migrations'] .= ' '.$late.' migration(s) déjà effective(s) ont été marquées comme appliquées.';
            }
        } catch (Throwable $e) {
            $errors['migrations'] = $e->getMessage();
        }

        // 2. Seed (idempotent — ne remplace jamais le mot de passe admin)
        try {
            Artisan::call('db:seed', ['--force' => true]);
            $results['seed'] = trim(Artisan::output()) ?: 'Aucune donnée à semer.';
        } catch (Throwable $e) {
            $errors['seed'] = $e->getMessage();
        }

        // 3. Nettoyage des caches
        try {
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            Artisan::call('cache:clear');

            $results['caches'] = 'Caches configuration, routes, vues et données vidés.';
        } catch (Throwable $e) {
            $errors['caches'] = $e->getMessage();
        }

        // 4. Caches d'optimisation : le gain de rapidité le plus net en
        //    production. Aucune route du projet n'est définie par closure,
        //    donc `route:cache` peut sérialiser la table de routage.
        try {
            Artisan::call('config:cache');
            $results['optimisation'] = 'Configuration mise en cache.';
        } catch (Throwable $e) {
            $errors['optimisation'] = $e->getMessage();
        }

        try {
            Artisan::call('route:cache');
            $results['routes'] = 'Table de routage mise en cache.';
        } catch (Throwable $e) {
            // Une route par closure ajoutée plus tard ferait échouer la
            // sérialisation : on continue sans cache de routes.
            $errors['routes'] = 'Routes laissées non cachées : '.$e->getMessage();
        }

        // 5. Tourner le jeton (le prochain update nécessitera le nouveau jeton)
        $this->rotateToken();

        return view('update', [
            'token' => null,
            'pending' => [],
            'alreadyInPlace' => [],
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'envExists' => true,
            'dbOk' => true,
            'results' => $results,
            'errors' => $errors,
            'done' => true,
            'newToken' => $this->readToken(),
        ]);
    }

    /**
     * Vérifie le jeton fourni et le renvoie.
     */
    private function validatedToken(Request $request): string
    {
        $expected = $this->readToken();
        $provided = (string) $request->input('token', $request->query('token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(403, 'Jeton de mise à jour invalide.');
        }

        return $provided;
    }

    private function abortIfNoEnv(): void
    {
        if (! is_file(base_path('.env'))) {
            abort(404, 'Aucune installation détectée (.env absent).');
        }
    }

    private function abortIfNoToken(): void
    {
        if (! is_file(base_path(self::TOKEN_PATH))) {
            abort(404, 'Fichier de jeton introuvable : '.base_path(self::TOKEN_PATH));
        }
    }

    private function readToken(): string
    {
        return trim((string) file_get_contents(base_path(self::TOKEN_PATH)));
    }

    private function dbReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Génère un nouveau jeton et remplace l'ancien.
     */
    private function rotateToken(): void
    {
        $newToken = bin2hex(random_bytes(24));

        file_put_contents(base_path(self::TOKEN_PATH), $newToken.PHP_EOL);
    }
}

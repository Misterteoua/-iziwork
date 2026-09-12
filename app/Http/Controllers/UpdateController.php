<?php

namespace App\Http\Controllers;

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

        $expected = $this->readToken();
        $provided = (string) $request->input('token', $request->query('token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(403, "Jeton de mise à jour invalide.");
        }

        $pending = $this->pendingMigrations();

        return view('update', [
            'token' => $provided,
            'pending' => $pending,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'envExists' => true,
            'dbOk' => $this->dbReachable(),
        ]);
    }

    /**
     * Lance la mise à jour : migrations + nettoyage des caches.
     */
    public function run(Request $request)
    {
        $this->abortIfNoEnv();
        $this->abortIfNoToken();

        $expected = $this->readToken();
        $provided = (string) $request->input('token', $request->query('token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(403, "Jeton de mise à jour invalide.");
        }

        if (! $this->dbReachable()) {
            return back()->with('error', 'Connexion à la base de données impossible.');
        }

        $results = [];
        $errors = [];

        // 1. Migrations
        try {
            Artisan::call('migrate', ['--force' => true]);
            $results['migrations'] = trim(Artisan::output()) ?: 'Aucune migration en attente.';
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

            // Re-créer les caches de production
            Artisan::call('config:cache');
            Artisan::call('route:cache');

            $results['caches'] = 'Config, routes et vues vidés puis recréés.';
        } catch (Throwable $e) {
            $errors['caches'] = $e->getMessage();
        }

        // 4. Tourner le jeton (le prochain update nécessitera le nouveau jeton)
        $this->rotateToken();

        return view('update', [
            'token' => null,
            'pending' => [],
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

    private function abortIfNoEnv(): void
    {
        if (! is_file(base_path('.env'))) {
            abort(404, 'Aucune installation détectée (.env absent).');
        }
    }

    private function abortIfNoToken(): void
    {
        if (! is_file(base_path(self::TOKEN_PATH))) {
            abort(404, "Fichier de jeton introuvable : ".base_path(self::TOKEN_PATH));
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

    private function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $paths = [database_path('migrations')];
            $files = $migrator->getMigrationFiles($paths);
            $ran = DB::table('migrations')->pluck('migration')->all();

            return array_values(array_diff($files, $ran));
        } catch (Throwable) {
            return ['(impossible de vérifier)'];
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

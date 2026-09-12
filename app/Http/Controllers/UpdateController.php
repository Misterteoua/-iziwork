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

        // 0. Synchroniser les migrations déjà appliquées (setup.sql / import externe)
        try {
            $synced = $this->syncMigrations();
            if ($synced > 0) {
                $results['sync'] = $synced.' migration(s) synchronisee(s) dans la table Laravel.';
            }
        } catch (Throwable $e) {
            $errors['sync'] = 'Synchronisation ignoree : '.$e->getMessage();
        }

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
     * Synchronise la table migrations avec les fichiers de migration.
     * Utilise quand la base a été créée via setup.sql (SQL brut) :
     * les tables existent mais la table migrations est vide.
     *
     * @return int Nombre de migrations insérées.
     */
    /**
     * Synchronise les migrations deja appliquees par setup.sql.
     *
     * Seules les migrations dont les tables existent deja sont marquees
     * comme faites. Les migrations nouvelles (ajout de colonnes, etc.)
     * restent en attente pour etre executees par Artisan::call("migrate").
     *
     * @return int Nombre de migrations synchronisees.
     */
    private function syncMigrations(): int
    {
        $allMigrations = collect(
            glob(database_path('migrations/*.php'))
        )
            ->map(fn (string $path) => basename($path, '.php'))
            ->sort()
            ->values();

        $existing = DB::table('migrations')
            ->pluck('migration')
            ->toArray();

        $missing = $allMigrations->diff($existing);

        if ($missing->isEmpty()) {
            return 0;
        }

        // Pour chaque migration en attente, verifie si la table principale
        // existe deja. Si oui, c'est que setup.sql l'a creee : on marque.
        // Sinon, c'est une vraie migration a executer.
        $prefix = config('database.connections.mysql.prefix', '');
        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => reset($row))
            ->toArray();

        $toSync = $missing->filter(function (string $name) use ($tables, $prefix) {
            // Extraire le nom de la table creee par cette migration
            // Format: YYYY_MM_DD_HHMMSS_create_TABlename_table
            if (preg_match('/_create_(\w+)_table$/', $name, $m)) {
                $table = $prefix . $m[1];
                return in_array($table, $tables);
            }
            // Migration d'ajout de colonne : on verifie si la table cible
            // contient deja la colonne (creee par setup.sql).
            return false;
        })->values();

        if ($toSync->isEmpty()) {
            return 0;
        }

        DB::table('migrations')->insert(
            $toSync->map(fn (string $name) => [
                'migration' => $name,
                'batch' => 1,
                'created_at' => now(),
            ])->all()
        );

        return $toSync->count();
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

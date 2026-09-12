<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes d'installation
|--------------------------------------------------------------------------
|
| Ce fichier est chargé HORS du groupe de middlewares « web » (voir le hook
| `then` dans bootstrap/app.php). C'est volontaire : l'assistant doit répondre
| avant que .env n'existe, donc sans session, sans cookie chiffré (APP_KEY
| absente) et sans jeton CSRF.
|
| La protection vient de deux mécanismes propres au contrôleur :
|   • le jeton secret `.install-token`, placé hors de public/ ;
|   • le verrou `storage/app/install.lock`, qui fait renvoyer 404 à ces
|     routes une fois l'installation terminée.
|
*/

Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');

/*
|--------------------------------------------------------------------------
| Routes de mise à jour
|--------------------------------------------------------------------------
|
| Similaire à l'assistant d'installation : ces routes fonctionnent hors du
| groupe « web » (sans session, sans CSRF). La protection repose sur le
| jeton `.update-token`, tourné après chaque mise à jour réussie.
|
*/

Route::get('/update', [App\Http\Controllers\UpdateController::class, 'show'])->name('update.show');
Route::post('/update', [App\Http\Controllers\UpdateController::class, 'run'])->name('update.run');

/*
|--------------------------------------------------------------------------
| Route de secours : synchroniser les migrations
|--------------------------------------------------------------------------
|
| Lorsque la base a été créée via setup.sql (SQL brut) ou un outil
| externe, la table migrations est vide : Laravel croit que toutes les
| migrations sont en attente alors qu'elles sont déjà appliquées.
|
| Cette route insère les migrations manquantes d'un coup. Elle exige
| le jeton .update-token et se désactive automatiquement dès qu'il
| ne reste plus de migration en attente.
|
*/

Route::get('/fix-migrations', function () {
    // Protege par le jeton de mise à jour.
    $tokenFile = base_path('.update-token');
    if (! file_exists($tokenFile)) {
        abort(404, 'Fichier de jeton introuvable.');
    }
    $expected = trim(file_get_contents($tokenFile));
    $provided = request()->query('token', '');

    if ($expected === '' || ! hash_equals($expected, $provided)) {
        abort(403, 'Jeton invalide.');
    }

    try {
        // Liste de toutes les migrations du projet, par ordre de traitement.
        $allMigrations = collect(
            glob(database_path('migrations/*.php'))
        )
            ->map(fn (string $path) => basename($path, '.php'))
            ->sort()
            ->values();

        // Migrations déjà présentes dans la table.
        $existing = DB::table('migrations')
            ->pluck('migration')
            ->toArray();

        $missing = $allMigrations->diff($existing);

        if ($missing->isEmpty()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Aucune migration manquante. La base est synchronisee.',
            ]);
        }

        // Insertion en un seul lot, batch = 1 (historique coherent).
        DB::table('migrations')->insert(
            $missing->map(fn (string $name) => [
                'migration' => $name,
                'batch' => 1,
                'created_at' => now(),
            ])->all()
        );

        return response()->json([
            'status' => 'ok',
            'message' => count($missing).' migration(s) synchronisee(s) avec succes.',
            'migrations' => $missing->all(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur : '.$e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ], 500);
    }
})->name('fix.migrations');

<?php

use App\Http\Controllers\InstallController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes d'installation et de mise à jour
|--------------------------------------------------------------------------
|
| Ce fichier est chargé HORS du groupe de middlewares « web » (voir le hook
| `then` dans bootstrap/app.php). C'est volontaire : les assistants doivent
| répondre avant que .env n'existe, donc sans session, sans cookie chiffré
| (APP_KEY absente) et sans jeton CSRF.
|
| La protection vient de mécanismes propres aux contrôleurs :
|   • le jeton secret `.install-token`, placé hors de public/ ;
|   • le verrou `storage/app/install.lock`, qui fait renvoyer 404 à ces
|     routes une fois l'installation terminée ;
|   • le jeton `.update-token`, tourné après chaque mise à jour.
|
| Aucune route par closure ici : `php artisan route:cache` doit pouvoir
| sérialiser l'ensemble des routes du projet.
|
*/

Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');

Route::get('/update', [UpdateController::class, 'show'])->name('update.show');
Route::post('/update', [UpdateController::class, 'run'])->name('update.run');

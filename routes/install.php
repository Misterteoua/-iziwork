<?php

use App\Http\Controllers\InstallController;
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

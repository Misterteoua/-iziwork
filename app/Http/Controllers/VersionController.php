<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Version déployée, utile pour vérifier qu'une archive a bien été mise en
 * ligne. Contrôleur dédié (et non closure) pour que `php artisan route:cache`
 * puisse sérialiser l'ensemble des routes du projet.
 */
class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'version' => config('app.version', 'dev'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
        ]);
    }
}

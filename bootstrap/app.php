<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\GraderAuth;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Assistant d'installation web. Volontairement hors du groupe
            // « web » : il doit fonctionner avant que .env n'existe, donc
            // sans session, sans cookie chiffré et sans jeton CSRF.
            Route::group([], base_path('routes/install.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'admin.auth' => AdminAuth::class,
            // Les correcteurs externes ont leur propre garde : deux clés de
            // session distinctes, donc aucune page d'administration ne peut
            // s'ouvrir à un correcteur, même par erreur de câblage.
            'grader.auth' => GraderAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

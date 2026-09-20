<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('submissions', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip().'|'.(string) $request->route('token'));
        });

        // Connexion d'un correcteur : même frein que pour l'administration, et
        // sur le même couple — l'email visé et l'adresse d'où l'on essaie. Une
        // référence de dix caractères ne se devine pas, mais rien n'oblige à
        // rendre la tentative confortable.
        RateLimiter::for('grader-login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}

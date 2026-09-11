<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Compte administrateur initial
    |--------------------------------------------------------------------------
    |
    | Valeurs utilisées par DatabaseSeeder pour créer le premier compte admin.
    |
    | Elles passent par la config et non par env() directement : quand la
    | configuration est mise en cache (`php artisan config:cache`), Laravel ne
    | charge plus le fichier .env et env() renverrait null — le seeder croirait
    | alors que ADMIN_PASSWORD est vide et refuserait de créer le compte.
    |
    | Après toute modification de ces valeurs dans .env, relancez
    | `php artisan config:clear` (puis `config:cache` en production).
    |
    */

    'email' => env('ADMIN_EMAIL', 'admin@iziwork.com'),

    'username' => env('ADMIN_USERNAME', 'admin'),

    'password' => env('ADMIN_PASSWORD', ''),

];

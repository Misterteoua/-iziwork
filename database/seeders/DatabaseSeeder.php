<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: re-running the seeder must not fail or reset an
        // existing admin's password.
        AdminUser::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@iziwork.com')],
            [
                'username' => env('ADMIN_USERNAME', 'admin'),
                'password_hash' => Hash::make((string) env('ADMIN_PASSWORD', 'password')),
                'role' => 'admin',
            ]
        );
    }
}

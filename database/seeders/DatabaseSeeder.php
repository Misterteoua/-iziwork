<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::create([
            'username' => env('ADMIN_USERNAME', 'admin'),
            'email' => env('ADMIN_EMAIL', 'admin@iziwork.com'),
            'password_hash' => Hash::make((string) env('ADMIN_PASSWORD', 'password')),
            'role' => 'admin',
        ]);
    }
}

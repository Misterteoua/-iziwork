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
            'username' => 'admin',
            'email' => 'admin@iziwork.com',
            'password_hash' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }
}

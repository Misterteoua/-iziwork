<?php

namespace Tests\Unit;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_can_be_created(): void
    {
        $user = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->assertDatabaseHas('admin_users', [
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'role' => 'admin',
        ]);
    }

    public function test_admin_user_has_forms_relationship(): void
    {
        $user = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->assertIsArray($user->forms->toArray());
    }

    public function test_admin_user_password_is_hashed(): void
    {
        $user = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => 'plain-password',
            'role' => 'admin',
        ]);

        $this->assertNotEquals('plain-password', $user->password_hash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('plain-password', $user->password_hash));
    }

    public function test_admin_user_username_is_unique(): void
    {
        AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test1@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test2@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_admin_user_email_is_unique(): void
    {
        AdminUser::create([
            'username' => 'testadmin1',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AdminUser::create([
            'username' => 'testadmin2',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }
}

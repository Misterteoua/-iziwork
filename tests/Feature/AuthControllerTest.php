<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_login_page_is_displayed(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Connexion');
    }

    public function test_invalid_admin_session_cannot_access_dashboard(): void
    {
        $this->session(['admin_user' => ['id' => 999999, 'username' => 'admin', 'email' => 'admin@test.com', 'role' => 'admin']]);

        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/admin');
        $response->assertSessionHas('admin_user');
    }

    public function test_user_cannot_login_with_invalid_email(): void
    {
        $response = $this->post('/login', [
            'email' => 'wrong@test.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $response->assertRedirect();
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $response->assertRedirect();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@test.com',
                'password' => 'wrongpassword',
            ])->assertRedirect();
        }

        $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ])->assertStatus(429);
    }

    public function test_user_can_logout(): void
    {
        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $response->assertSessionMissing('admin_user');
    }

    public function test_authenticated_user_is_redirected_from_login(): void
    {
        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $response = $this->get('/login');

        $response->assertRedirect('/admin');
    }
}

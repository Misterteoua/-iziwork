<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('MotDePasse123'),
            'role' => 'admin',
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);
    }

    public function test_profile_page_displays_account_information(): void
    {
        $response = $this->get('/admin/profile');

        $response->assertStatus(200);
        $response->assertSee('Mon profil');
        $response->assertSee('admin@test.com');
        $response->assertSee('Changer le mot de passe');
    }

    public function test_guest_cannot_access_profile(): void
    {
        $this->app['session']->flush();

        $response = $this->get('/admin/profile');

        $response->assertRedirect('/login');
    }

    public function test_password_can_be_changed_with_valid_current_password(): void
    {
        $response = $this->patch('/admin/profile/password', [
            'current_password' => 'MotDePasse123',
            'password' => 'NouveauMotDePasse456',
            'password_confirmation' => 'NouveauMotDePasse456',
        ]);

        $response->assertRedirect('/admin/profile');
        $response->assertSessionHas('success');

        $this->assertTrue(Hash::check('NouveauMotDePasse456', $this->admin->fresh()->password_hash));
        $this->assertFalse(Hash::check('MotDePasse123', $this->admin->fresh()->password_hash));
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $response = $this->patch('/admin/profile/password', [
            'current_password' => 'MauvaisMotDePasse',
            'password' => 'NouveauMotDePasse456',
            'password_confirmation' => 'NouveauMotDePasse456',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('current_password');

        // The password must be unchanged.
        $this->assertTrue(Hash::check('MotDePasse123', $this->admin->fresh()->password_hash));
    }

    public function test_password_change_requires_matching_confirmation(): void
    {
        $response = $this->patch('/admin/profile/password', [
            'current_password' => 'MotDePasse123',
            'password' => 'NouveauMotDePasse456',
            'password_confirmation' => 'DifferentMotDePasse789',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('MotDePasse123', $this->admin->fresh()->password_hash));
    }

    public function test_password_change_enforces_minimum_length(): void
    {
        $response = $this->patch('/admin/profile/password', [
            'current_password' => 'MotDePasse123',
            'password' => 'court123',
            'password_confirmation' => 'court123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('MotDePasse123', $this->admin->fresh()->password_hash));
    }

    public function test_session_stays_authenticated_after_password_change(): void
    {
        $this->patch('/admin/profile/password', [
            'current_password' => 'MotDePasse123',
            'password' => 'NouveauMotDePasse456',
            'password_confirmation' => 'NouveauMotDePasse456',
        ]);

        // The session was regenerated, not destroyed: the admin must still
        // be able to browse protected pages without logging in again.
        $response = $this->get('/admin');

        $response->assertStatus(200);
    }
}

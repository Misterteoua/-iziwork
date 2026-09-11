<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $originalEnv = [];

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ADMIN_EMAIL', 'ADMIN_USERNAME', 'ADMIN_PASSWORD'] as $key) {
            $this->originalEnv[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'putenv' => getenv($key),
            ];

            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        foreach (['email', 'username', 'password'] as $key) {
            $this->originalConfig[$key] = config("admin.{$key}");

            config(["admin.{$key}" => null]);
        }

        // Neutral defaults: every test opts in explicitly.
        config([
            'admin.email' => 'admin@iziwork.com',
            'admin.username' => 'admin',
            'admin.password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $original) {
            if ($original['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $original['env'];
            }

            if ($original['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $original['server'];
            }

            if ($original['putenv'] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$original['putenv']}");
            }
        }

        foreach ($this->originalConfig as $key => $value) {
            config(["admin.{$key}" => $value]);
        }

        parent::tearDown();
    }

    public function test_seeder_creates_the_admin_configured_for_the_application(): void
    {
        config([
            'admin.email' => 'directeur@efspc.inphb.ci',
            'admin.username' => 'directeur',
            'admin.password' => 'un-mot-de-passe-fort',
        ]);

        (new DatabaseSeeder)->run();

        $admin = AdminUser::where('email', 'directeur@efspc.inphb.ci')->first();

        $this->assertNotNull($admin);
        $this->assertSame('directeur', $admin->username);
        $this->assertTrue(Hash::check('un-mot-de-passe-fort', $admin->password_hash));
    }

    /**
     * Regression: with a cached configuration (`php artisan config:cache`)
     * Laravel never loads .env, so env('ADMIN_PASSWORD') is null. The seeder
     * must still find the credentials through config(), otherwise it would
     * wrongly refuse to create the admin on a production server.
     */
    public function test_seeder_reads_credentials_from_config_when_env_is_not_loaded(): void
    {
        $this->assertNull(env('ADMIN_PASSWORD'));

        config([
            'admin.email' => 'directeur@efspc.inphb.ci',
            'admin.password' => 'mot-de-passe-de-production',
        ]);

        (new DatabaseSeeder)->run();

        $admin = AdminUser::where('email', 'directeur@efspc.inphb.ci')->firstOrFail();

        $this->assertTrue(Hash::check('mot-de-passe-de-production', $admin->password_hash));
    }

    public function test_seeder_is_idempotent_and_never_resets_an_existing_password(): void
    {
        config([
            'admin.email' => 'directeur@efspc.inphb.ci',
            'admin.password' => 'premier-mot-de-passe',
        ]);

        (new DatabaseSeeder)->run();

        config(['admin.password' => 'second-mot-de-passe']);
        (new DatabaseSeeder)->run();

        $this->assertSame(1, AdminUser::count());

        $admin = AdminUser::where('email', 'directeur@efspc.inphb.ci')->firstOrFail();

        $this->assertTrue(Hash::check('premier-mot-de-passe', $admin->password_hash));
        $this->assertFalse(Hash::check('second-mot-de-passe', $admin->password_hash));
    }

    public function test_seeder_fails_loudly_in_production_when_password_is_empty(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config(['admin.email' => 'directeur@efspc.inphb.ci']);

        $this->expectException(RuntimeException::class);

        try {
            (new DatabaseSeeder)->run();
        } finally {
            // No admin must be created when the password is missing: an empty
            // hash would leave nobody able to log in.
            $this->assertSame(0, AdminUser::count());
        }
    }
}

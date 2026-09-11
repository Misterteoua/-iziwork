<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Password used when ADMIN_PASSWORD is not defined in a local environment.
     */
    private const LOCAL_DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        // Read through config() (see config/admin.php), never env() directly:
        // when the configuration is cached (`php artisan config:cache`) Laravel
        // does not load .env at all and env() would return null, making the
        // seeder wrongly believe ADMIN_PASSWORD is empty.
        $email = (string) config('admin.email', 'admin@iziwork.com');
        $username = (string) config('admin.username', 'admin');

        // Note: an empty "ADMIN_PASSWORD=" line yields an empty string, not the
        // default, which would create an admin nobody can log into. Treat
        // "unset" and "empty" the same way.
        $password = (string) config('admin.password', '');

        if ($password === '') {
            if (! app()->environment('local', 'testing')) {
                throw new RuntimeException(
                    'ADMIN_PASSWORD est vide : le compte administrateur n\'a pas été créé. '
                    .'Renseignez une valeur forte pour ADMIN_PASSWORD dans le fichier .env, '
                    .'puis relancez "php artisan db:seed".'
                );
            }

            $password = self::LOCAL_DEFAULT_PASSWORD;
            $this->command?->warn(
                'ADMIN_PASSWORD non défini : mot de passe par défaut "'.self::LOCAL_DEFAULT_PASSWORD
                .'" utilisé (environnement '.app()->environment().').'
            );
        }

        // Idempotent: re-running the seeder must not fail or reset an
        // existing admin's password.
        $existing = AdminUser::where('email', $email)->first();

        if ($existing !== null) {
            $this->command?->info("Compte administrateur déjà présent ({$email}) — mot de passe inchangé.");

            return;
        }

        AdminUser::create([
            'username' => $username,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'role' => 'admin',
        ]);

        $this->command?->info("Compte administrateur créé : {$email}");
    }
}

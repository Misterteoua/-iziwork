<?php

namespace Tests\Unit;

use App\Support\DeploymentVersion;
use Tests\TestCase;

/**
 * Une mise à jour extrait une archive par-dessus l'installation : le code
 * change, le `.env` du serveur non. La version annoncée par `/version` doit
 * pourtant suivre, sinon le contrôle qui suit une extraction ne dit pas si
 * l'archive en place est bien celle qui répond.
 */
class DeploymentVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'iziwork-version-'.uniqid();

        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    private function file(string $name, string $content): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private const ENV = "APP_NAME=Iziwork\nAPP_VERSION=2026.01.01.0000\nAPP_KEY=base64:cle=\n";

    private const TEMPLATE = "APP_NAME=Iziwork\nAPP_VERSION=2026.10.14.2245\n";

    public function test_la_version_du_gabarit_rejoint_le_env(): void
    {
        $env = $this->file('.env', self::ENV);
        $template = $this->file('.env.production.example', self::TEMPLATE);

        $this->assertSame('2026.10.14.2245', DeploymentVersion::stamp($env, $template));

        $written = (string) file_get_contents($env);

        $this->assertStringContainsString('APP_VERSION=2026.10.14.2245', $written);
        $this->assertStringNotContainsString('2026.01.01.0000', $written);
        // Rien d'autre n'a bougé : la clé d'application et les identifiants
        // sont ce qui rend une mise à jour sans réinstallation possible.
        $this->assertStringContainsString('APP_NAME=Iziwork', $written);
        $this->assertStringContainsString('APP_KEY=base64:cle=', $written);
    }

    public function test_un_env_deja_a_jour_n_est_pas_reécrit(): void
    {
        $env = $this->file('.env', self::ENV);
        $template = $this->file('.env.production.example', self::TEMPLATE);
        $before = (string) file_get_contents($env);

        DeploymentVersion::stamp($env, $template);
        $stamped = (string) file_get_contents($env);

        // Deuxième passage : plus rien à faire, et le fichier reste identique.
        $this->assertNull(DeploymentVersion::stamp($env, $template));
        $this->assertSame($stamped, (string) file_get_contents($env));
        $this->assertNotSame($before, $stamped);
    }

    public function test_un_env_sans_app_version_gagne_la_ligne(): void
    {
        $env = $this->file('.env', "APP_NAME=Iziwork\nAPP_KEY=base64:cle=\n");
        $template = $this->file('.env.production.example', self::TEMPLATE);

        $this->assertSame('2026.10.14.2245', DeploymentVersion::stamp($env, $template));
        $this->assertStringContainsString('APP_VERSION=2026.10.14.2245', (string) file_get_contents($env));
    }

    public function test_sans_gabarit_le_env_est_laisse_intact(): void
    {
        $env = $this->file('.env', self::ENV);
        $before = (string) file_get_contents($env);

        $this->assertNull(DeploymentVersion::stamp($env, $this->directory.DIRECTORY_SEPARATOR.'absent.example'));
        $this->assertSame($before, (string) file_get_contents($env));
    }

    public function test_un_gabarit_sans_app_version_ne_chamboule_rien(): void
    {
        $env = $this->file('.env', self::ENV);
        $template = $this->file('.env.production.example', "APP_NAME=Iziwork\n");

        $this->assertNull(DeploymentVersion::stamp($env, $template));
        $this->assertSame(self::ENV, (string) file_get_contents($env));
    }

    public function test_un_env_absent_est_signale_par_l_appelant_et_non_par_une_erreur(): void
    {
        $template = $this->file('.env.production.example', self::TEMPLATE);

        $this->assertNull(DeploymentVersion::stamp($this->directory.DIRECTORY_SEPARATOR.'.env', $template));
    }

    public function test_la_version_est_lue_meme_avec_un_retour_chariot(): void
    {
        $env = $this->file('.env', "APP_VERSION=2026.01.01.0000\r\n");
        $template = $this->file('.env.production.example', "APP_VERSION=2026.10.14.2245\r\n");

        $this->assertSame('2026.01.01.0000', DeploymentVersion::read((string) file_get_contents($env)));
        $this->assertSame('2026.10.14.2245', DeploymentVersion::stamp($env, $template));
    }
}

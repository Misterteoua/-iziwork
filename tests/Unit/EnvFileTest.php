<?php

namespace Tests\Unit;

use App\Support\EnvFile;
use Dotenv\Dotenv;
use Tests\TestCase;

/**
 * L'écriture d'une valeur dans un .env est partagée par l'assistant
 * d'installation (qui compose le fichier complet) et par l'assistant de mise à
 * jour (qui n'y rafraîchit que la version). Deux comportements comptent : ne
 * toucher qu'à la ligne visée, et écrire des valeurs que phpdotenv relit à
 * l'identique — un mot de passe mal quoté est un mot de passe perdu.
 */
class EnvFileTest extends TestCase
{
    public function test_la_ligne_existante_est_remplacee_et_le_reste_intact(): void
    {
        $content = implode("\n", [
            'APP_NAME=Iziwork',
            'APP_VERSION=2026.01.01.0000',
            'APP_KEY=base64:abc=',
            '',
        ]);

        $updated = EnvFile::set($content, 'APP_VERSION', '2026.10.14.2245');

        $this->assertStringContainsString('APP_VERSION=2026.10.14.2245', $updated);
        $this->assertStringNotContainsString('2026.01.01.0000', $updated);
        // Une clé dont le nom commence pareil ne doit pas être confondue.
        $this->assertStringContainsString('APP_NAME=Iziwork', $updated);
        $this->assertStringContainsString('APP_KEY=base64:abc=', $updated);
        $this->assertSame(1, substr_count($updated, 'APP_VERSION='));
    }

    public function test_une_cle_absente_est_ajoutee_en_fin_de_fichier(): void
    {
        $updated = EnvFile::set("APP_NAME=Iziwork\n", 'APP_VERSION', '2026.10.14.2245');

        $this->assertSame("APP_NAME=Iziwork\nAPP_VERSION=2026.10.14.2245\n", $updated);
    }

    public function test_une_cle_absente_rejoint_un_fichier_sans_retour_final(): void
    {
        $updated = EnvFile::set('APP_NAME=Iziwork', 'APP_VERSION', '2026.10.14.2245');

        $this->assertSame("APP_NAME=Iziwork\nAPP_VERSION=2026.10.14.2245\n", $updated);
    }

    /**
     * Les valeurs réelles de DB_PASSWORD et ADMIN_PASSWORD : espaces,
     * apostrophe, dièse, guillemets, dollar et antislash. Elles doivent
     * ressortir telles quelles après relecture par phpdotenv.
     */
    public function test_les_valeurs_a_caracteres_speciaux_sont_relues_a_l_identique(): void
    {
        $valeurs = [
            'mot de passe avec espaces',
            "l'apostrophe et le # dièse",
            'le "guillemet" droit',
            'le $dollar et le \\antislash',
            'mélange $a "b" \\ c #fin',
        ];

        foreach ($valeurs as $valeur) {
            $updated = EnvFile::set("APP_NAME=Iziwork\n", 'MOT_DE_PASSE', $valeur);

            $this->assertSame(
                ['MOT_DE_PASSE' => $valeur],
                array_intersect_key(Dotenv::parse($updated), ['MOT_DE_PASSE' => true]),
                'Valeur mal encodée : '.$valeur
            );
        }
    }

    public function test_une_valeur_vide_reste_vide_et_reste_lisible(): void
    {
        $updated = EnvFile::set("MOT_DE_PASSE=secret\n", 'MOT_DE_PASSE', '');

        $this->assertSame("MOT_DE_PASSE=\n", $updated);
        $this->assertSame(['MOT_DE_PASSE' => ''], Dotenv::parse($updated));
    }
}

<?php

namespace App\Support;

use RuntimeException;

/**
 * Version déployée, telle que la lit `/version` — `config('app.version')`,
 * elle-même alimentée par `APP_VERSION` dans `.env`.
 *
 * À chaque release, tools/build-release.sh horodate `APP_VERSION` dans
 * `.env.production.example`, le gabarit embarqué dans l'archive. Mais
 * l'extraction par-dessus une installation existante ne touche pas au `.env`
 * du serveur — il doit garder sa clé, ses identifiants et ses secrets. Sans
 * cette reprise, `/version` annoncerait indéfiniment la version de
 * l'installation d'origine, et le contrôle qui suit une mise à jour ne
 * prouverait rien : impossible de dire si l'archive extraite est bien celle
 * qui répond.
 */
class DeploymentVersion
{
    /** Gabarit embarqué dans l'archive, à côté du `.env` du serveur. */
    public const TEMPLATE = '.env.production.example';

    /**
     * Recopie `APP_VERSION` du gabarit vers le `.env`, sans toucher à aucune
     * autre ligne. Renvoie la version appliquée, ou null quand il n'y a rien à
     * faire : `.env` ou gabarit absent, version illisible, ou déjà à jour.
     */
    public static function stamp(string $envPath, ?string $templatePath = null): ?string
    {
        $templatePath ??= base_path(self::TEMPLATE);

        if (! is_file($envPath) || ! is_file($templatePath)) {
            return null;
        }

        $env = (string) file_get_contents($envPath);
        $version = self::read((string) file_get_contents($templatePath));

        if ($version === null || $version === self::read($env)) {
            return null;
        }

        // Seule la ligne APP_VERSION change : le reste du .env — clé
        // d'application, base de données, secrets — est recopié tel quel.
        $updated = EnvFile::set($env, 'APP_VERSION', $version);

        if (file_put_contents($envPath, $updated) === false) {
            throw new RuntimeException('Écriture refusée dans '.$envPath);
        }

        return $version;
    }

    /**
     * Lit `APP_VERSION` dans le contenu d'un `.env`. Renvoie null quand la clé
     * est absente ou vide — un `.env` d'avant la clé mérite d'être complété.
     */
    public static function read(string $contents): ?string
    {
        if (preg_match('/^APP_VERSION=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }
}

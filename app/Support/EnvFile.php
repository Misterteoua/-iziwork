<?php

namespace App\Support;

/**
 * Écriture d'une valeur dans le contenu d'un fichier .env.
 *
 * Une seule implémentation, partagée : l'assistant d'installation s'en sert
 * pour composer le .env complet, l'assistant de mise à jour pour y rafraîchir
 * la version déployée. Deux copies divergentes finiraient par produire deux
 * .env différents selon le chemin emprunté.
 */
class EnvFile
{
    /**
     * Remplace la ligne KEY=... existante, ou l'ajoute en fin de fichier.
     */
    public static function set(string $content, string $key, string $value): string
    {
        $line = $key.'='.self::encodeValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content) === 1) {
            // preg_replace_callback : la valeur peut contenir "$" ou "\", qui
            // seraient interprétés comme références dans un remplacement brut.
            return (string) preg_replace_callback($pattern, fn (): string => $line, $content, 1);
        }

        return rtrim($content, "\n")."\n".$line."\n";
    }

    /**
     * Encode une valeur pour .env.
     *
     * Mesuré avec vlucas/phpdotenv 5.7 : les guillemets doubles (avec "\" et
     * "\"" échappés) sont le seul encodage qui relit correctement toutes les
     * valeurs, y compris celles contenant #, $, ", ' ou des espaces. Les
     * guillemets simples, eux, échouent dès que la valeur contient une
     * apostrophe ; les valeurs non quotées sont tronquées au premier #.
     */
    public static function encodeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z0-9_.:/@+-]+$#', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}

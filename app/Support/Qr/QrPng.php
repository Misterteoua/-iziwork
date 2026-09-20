<?php

namespace App\Support\Qr;

use RuntimeException;

/**
 * Rendu PNG d'une matrice QR, écrit à la main.
 *
 * Pourquoi pas GD ? Parce que l'extension n'est pas garantie sur un hébergement
 * mutualisé, et qu'un carré noir et blanc en niveaux de gris tient en quelques
 * lignes : en-tête IHDR, données zlib, blocs IDAT et IEND avec leur CRC. Le
 * résultat est une image PNG de base64, affichable sans JavaScript et
 * intégrable telle quelle dans un PDF.
 */
final class QrPng
{
    /** Marge blanche exigée par le standard, en modules. */
    public const QUIET_ZONE = 4;

    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Le QR d'un texte, prêt pour un attribut src.
     */
    public static function dataUri(string $text, int $scale = 8): string
    {
        return 'data:image/png;base64,'.base64_encode(self::render(QrEncoder::encode($text), $scale));
    }

    public static function render(QrMatrix $matrix, int $scale = 8, int $quietZone = self::QUIET_ZONE): string
    {
        $scale = max(1, $scale);
        $quietZone = max(0, $quietZone);

        $side = ($matrix->size() + $quietZone * 2) * $scale;

        $raw = '';

        for ($y = 0; $y < $side; $y++) {
            $row = intdiv($y, $scale) - $quietZone;

            // Le premier octet de chaque ligne est le filtre PNG : 0, aucun.
            $line = "\x00";

            for ($x = 0; $x < $side; $x++) {
                $column = intdiv($x, $scale) - $quietZone;

                $dark = $row >= 0 && $column >= 0
                    && $row < $matrix->size() && $column < $matrix->size()
                    && $matrix->isDark($row, $column);

                $line .= $dark ? "\x00" : "\xFF";
            }

            $raw .= $line;
        }

        $compressed = gzcompress($raw, 9);

        if ($compressed === false) {
            throw new RuntimeException('Compression PNG impossible.');
        }

        // IHDR : largeur, hauteur, 8 bits par pixel, niveaux de gris, aucun
        // filtre ni entrelacement particuliers.
        return self::SIGNATURE
            .self::chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 0, 0, 0, 0))
            .self::chunk('IDAT', $compressed)
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data) & 0xFFFFFFFF);
    }
}

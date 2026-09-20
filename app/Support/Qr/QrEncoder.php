<?php

namespace App\Support\Qr;

use InvalidArgumentException;

/**
 * Encodeur QR, écrit à la main : mode octets, niveau de correction M, versions 1
 * à 10 — largement de quoi encoder une URL de lien court.
 *
 * Pourquoi pas une bibliothèque ? Le projet lit déjà l'Excel et le Word à la
 * main, et l'archive de release promet de ne rien avoir à installer. Un QR
 * code, c'est une spécification fermée (ISO/CEI 18004) et un rendu : ni plus,
 * ni moins. Le rendu se fait côté serveur (voir QrPng), donc l'image s'affiche
 * sans JavaScript et se retrouve telle quelle dans un PDF.
 *
 * Ce que ce code garantit, et que les tests vérifient : les codewords de
 * correction sont un vrai code de Reed-Solomon (leurs syndromes sont nuls), les
 * bits de format appartiennent au code BCH(15,5) du standard, l'entrelacement
 * respecte la structure des blocs, et le masque retenu est celui qui minimise
 * la pénalité.
 */
final class QrEncoder
{
    /**
     * Structure des blocs, niveau M : [codewords de correction par bloc,
     * [[nombre de blocs, codewords de données], …]].
     *
     * Le niveau M tolère environ 15 % de dégradation : de quoi survivre à une
     * impression puis à une photocopie, sans grossir l'image inutilement.
     *
     * Les blocs les plus courts viennent en premier : c'est l'ordre du standard,
     * et c'est lui qui définit l'entrelacement.
     */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /**
     * Centres des motifs d'alignement : les coordonnées possibles de chaque
     * côté, tous les croisements étant garnis sauf ceux qui touchent un repère.
     *
     * Il n'y a pas de motif d'alignement en version 1.
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Niveau de correction M, codé sur deux bits : L = 01, M = 00, Q = 11, H = 10. */
    private const ECC_MEDIUM = 0b00;

    /** Masque appliqué aux bits de format (constante du standard). */
    private const FORMAT_MASK = 0x5412;

    public static function encode(string $text): QrMatrix
    {
        $bytes = array_values(unpack('C*', $text) ?: []);

        $version = self::versionFor(count($bytes));

        $payload = self::interleave(self::codewords($bytes, $version), $version);

        [$modules, $functions] = self::baseMatrix($version);

        self::drawCodewords($modules, $functions, $payload);

        // Le masque qui minimise la pénalité, informations de format comprises :
        // c'est sur le symbole tel qu'il sera lu que le standard compte les
        // motifs pénalisants.
        $best = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;

            self::applyMask($candidate, $functions, $mask);
            self::drawFormatBits($candidate, $mask, self::size($version));

            $penalty = self::penalty($candidate);

            if ($best === null || $penalty < $best['penalty']) {
                $best = ['mask' => $mask, 'penalty' => $penalty, 'modules' => $candidate];
            }
        }

        return new QrMatrix($best['modules'], $version, $best['mask']);
    }

    /**
     * Nombre de codewords de données d'une version (niveau M).
     *
     * Exposé pour les tests : c'est cette table qui décide de la version d'un
     * texte, donc de la lisibilité du QR imprimé.
     */
    public static function dataCodewordCount(int $version): int
    {
        $total = 0;

        foreach (self::BLOCKS[$version][1] as [$blocks, $words]) {
            $total += $blocks * $words;
        }

        return $total;
    }

    /**
     * Structure des blocs d'une version : codewords de correction par bloc,
     * nombre de blocs, codewords de données. Exposé pour que les tests
     * recoupent la table avec les constantes publiées du standard — une erreur
     * ici ne casserait rien visiblement, elle rendrait le QR illisible.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function blockStructure(int $version): array
    {
        [$ecPerBlock, $groups] = self::BLOCKS[$version];

        $blocks = 0;

        foreach ($groups as [$count, $words]) {
            $blocks += $count;
        }

        return [$ecPerBlock, $blocks, self::dataCodewordCount($version)];
    }

    /**
     * Codewords de correction d'un bloc, exposé pour que les tests vérifient la
     * propriété qui compte : évalués aux racines du polynôme générateur, ils
     * doivent donner zéro.
     *
     * @param  array<int, int>  $data
     * @return array<int, int>
     */
    public static function errorCorrection(array $data, int $degree): array
    {
        $divisor = self::divisor($degree);
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];

            array_shift($remainder);
            $remainder[] = 0;

            foreach ($divisor as $index => $coefficient) {
                $remainder[$index] ^= self::multiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    // ------------------------------------------------------------- Structure

    private static function size(int $version): int
    {
        return 17 + 4 * $version;
    }

    /**
     * Version la plus petite qui accueille le texte, mode octets : quatre bits
     * de mode et la longueur, sur huit bits jusqu'à la version 9 et seize
     * au-delà.
     */
    private static function versionFor(int $byteCount): int
    {
        foreach (array_keys(self::BLOCKS) as $version) {
            $header = $version <= 9 ? 2 : 3;

            if ($byteCount + $header <= self::dataCodewordCount($version)) {
                return $version;
            }
        }

        throw new InvalidArgumentException('Contenu trop long pour un QR code de version 10.');
    }

    /**
     * @return array{0: array<int, array<int, bool>>, 1: array<int, array<int, bool>>}
     */
    private static function baseMatrix(int $version): array
    {
        $size = self::size($version);

        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $functions = array_fill(0, $size, array_fill(0, $size, false));

        // Repères et leurs séparateurs, aux trois coins. Les coordonnées sont
        // celles du **centre** du motif, à trois modules du bord.
        self::drawFinder($modules, $functions, 3, 3, $size);
        self::drawFinder($modules, $functions, 3, $size - 4, $size);
        self::drawFinder($modules, $functions, $size - 4, 3, $size);

        // Motifs de synchronisation : ligne 6 et colonne 6, entre les repères.
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;

            $modules[6][$i] = $dark;
            $modules[$i][6] = $dark;
            $functions[6][$i] = true;
            $functions[$i][6] = true;
        }

        $positions = self::ALIGNMENT[$version];
        $last = count($positions) - 1;

        foreach ($positions as $i => $row) {
            foreach ($positions as $j => $column) {
                $touchesFinder = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $last)
                    || ($i === $last && $j === 0);

                if (! $touchesFinder) {
                    self::drawAlignment($modules, $functions, $row, $column, $size);
                }
            }
        }

        self::reserveFormatAreas($functions, $size);

        if ($version >= 7) {
            self::drawVersionBits($modules, $functions, $version, $size);
        }

        return [$modules, $functions];
    }

    private static function drawFinder(array &$modules, array &$functions, int $row, int $column, int $size): void
    {
        for ($y = -4; $y <= 4; $y++) {
            for ($x = -4; $x <= 4; $x++) {
                $r = $row + $y;
                $c = $column + $x;

                if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                    continue;
                }

                // Cœur noir (distance 0 à 1), anneau clair (2), anneau noir (3),
                // séparateur clair (4).
                $distance = max(abs($x), abs($y));

                $modules[$r][$c] = $distance !== 2 && $distance !== 4;
                $functions[$r][$c] = true;
            }
        }
    }

    private static function drawAlignment(array &$modules, array &$functions, int $row, int $column, int $size): void
    {
        for ($y = -2; $y <= 2; $y++) {
            for ($x = -2; $x <= 2; $x++) {
                $r = $row + $y;
                $c = $column + $x;

                if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                    continue;
                }

                $modules[$r][$c] = max(abs($x), abs($y)) !== 1;
                $functions[$r][$c] = true;
            }
        }
    }

    /**
     * Les deux zones d'informations de format : la ligne 8 et la colonne 8
     * autour du repère supérieur gauche, puis leurs prolongements en haut à
     * droite et en bas à gauche. Aucune donnée ne doit s'y loger.
     */
    private static function reserveFormatAreas(array &$functions, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $functions[8][$i] = true;
            $functions[$i][8] = true;
        }

        for ($i = 0; $i < 8; $i++) {
            $functions[8][$size - 1 - $i] = true;
            $functions[$size - 1 - $i][8] = true;
        }
    }

    /**
     * Informations de version (versions 7 et au-delà) : six bits prolongés en un
     * code BCH(18,6), répétés dans deux blocs de 3 × 6 modules.
     */
    private static function drawVersionBits(array &$modules, array &$functions, int $version, int $size): void
    {
        $remainder = $version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 11) & 1) * 0x1F25);
        }

        $bits = ($version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;

            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);

            $modules[$b][$a] = $dark;
            $modules[$a][$b] = $dark;
            $functions[$b][$a] = true;
            $functions[$a][$b] = true;
        }
    }

    // ----------------------------------------------------------- Codewords

    /**
     * @param  array<int, int>  $bytes
     * @return array<int, int>
     */
    private static function codewords(array $bytes, int $version): array
    {
        $capacity = self::dataCodewordCount($version);

        // Mode « octets » (0100), puis la longueur.
        $bits = '0100'.str_pad(decbin(count($bytes)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);

        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        // Terminateur : jusqu'à quatre zéros, puis alignement sur l'octet.
        $bits .= str_repeat('0', min(4, max(0, $capacity * 8 - strlen($bits))));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        // Octets de remplissage, alternés, jusqu'à la capacité exacte.
        for ($pad = 0; strlen($bits) < $capacity * 8; $pad++) {
            $bits .= $pad % 2 === 0 ? '11101100' : '00010001';
        }

        $codewords = [];

        for ($i = 0; $i < $capacity; $i++) {
            $codewords[] = bindec(substr($bits, $i * 8, 8));
        }

        return $codewords;
    }

    /**
     * Entrelacement du standard : les codewords de données, bloc par bloc, puis
     * les codewords de correction, bloc par bloc. C'est ce qui permet à une
     * tache de perdre un bloc entier sans rendre la lecture impossible.
     *
     * @param  array<int, int>  $data
     * @return array<int, int>
     */
    private static function interleave(array $data, int $version): array
    {
        [$ecPerBlock, $groups] = self::BLOCKS[$version];

        $blocks = [];
        $offset = 0;

        foreach ($groups as [$count, $words]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($data, $offset, $words);
                $offset += $words;

                $blocks[] = ['data' => $block, 'ec' => self::errorCorrection($block, $ecPerBlock)];
            }
        }

        $payload = [];
        $longest = max(array_map('count', array_column($blocks, 'data')));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if (array_key_exists($i, $block['data'])) {
                    $payload[] = $block['data'][$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($blocks as $block) {
                $payload[] = $block['ec'][$i];
            }
        }

        return $payload;
    }

    // ------------------------------------------------------ Reed-Solomon

    /**
     * Le polynôme générateur d'un bloc : le produit des (x − α^i) pour i de 0 à
     * degree − 1, dans GF(256) avec le polynôme primitif x^8+x^4+x^3+x^2+1.
     *
     * @return array<int, int>
     */
    private static function divisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    private static function multiply(int $x, int $y): int
    {
        $product = 0;

        for ($i = 7; $i >= 0; $i--) {
            $product = (($product << 1) ^ ((($product >> 7) & 1) * 0x11D)) & 0xFF;
            $product ^= (($y >> $i) & 1) * $x;
        }

        return $product & 0xFF;
    }

    // ---------------------------------------------------------- Placement

    /**
     * Les codewords sont posés en zigzag, deux colonnes à la fois, de droite à
     * gauche ; la colonne 6 est sautée, et les modules de fonction aussi.
     *
     * Les modules restants (les « bits de reste ») gardent leur valeur claire,
     * puis sont masqués comme les autres.
     *
     * @param  array<int, array<int, bool>>  $modules
     * @param  array<int, array<int, bool>>  $functions
     * @param  array<int, int>  $codewords
     */
    private static function drawCodewords(array &$modules, array $functions, array $codewords): void
    {
        $size = count($modules);
        $total = count($codewords) * 8;
        $index = 0;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            $upward = ((($right + 1) & 2) === 0);

            for ($vert = 0; $vert < $size; $vert++) {
                $row = $upward ? $size - 1 - $vert : $vert;

                for ($offset = 0; $offset < 2; $offset++) {
                    $column = $right - $offset;

                    if ($functions[$row][$column] || $index >= $total) {
                        continue;
                    }

                    $byte = $codewords[intdiv($index, 8)];
                    $modules[$row][$column] = (($byte >> (7 - $index % 8)) & 1) === 1;
                    $index++;
                }
            }
        }
    }

    /** @param array<int, array<int, bool>> $modules */
    private static function applyMask(array &$modules, array $functions, int $mask): void
    {
        $size = count($modules);

        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($functions[$row][$column] || ! self::masked($mask, $row, $column)) {
                    continue;
                }

                $modules[$row][$column] = ! $modules[$row][$column];
            }
        }
    }

    /** Les huit masques du standard. */
    private static function masked(int $mask, int $row, int $column): bool
    {
        $product = $row * $column;

        return match ($mask) {
            0 => ($row + $column) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $column % 3 === 0,
            3 => ($row + $column) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
            5 => $product % 2 + $product % 3 === 0,
            6 => ($product % 2 + $product % 3) % 2 === 0,
            default => (($row + $column) % 2 + $product % 3) % 2 === 0,
        };
    }

    /**
     * Informations de format : cinq bits utiles (niveau de correction puis
     * masque) prolongés en BCH(15,5) et masqués par 0x5412, écrits en deux
     * copies de part et d'autre.
     *
     * @param array<int, array<int, bool>> $modules
     */
    private static function drawFormatBits(array &$modules, int $mask, int $size): void
    {
        $bits = self::formatBits($mask);

        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = (($bits >> $i) & 1) === 1;
        }

        $modules[7][8] = (($bits >> 6) & 1) === 1;
        $modules[8][8] = (($bits >> 7) & 1) === 1;
        $modules[8][7] = (($bits >> 8) & 1) === 1;

        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = (($bits >> $i) & 1) === 1;
        }

        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = (($bits >> $i) & 1) === 1;
        }

        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = (($bits >> $i) & 1) === 1;
        }

        // Le module toujours noir, à côté des informations de format.
        $modules[$size - 8][8] = true;
    }

    private static function formatBits(int $mask): int
    {
        $data = (self::ECC_MEDIUM << 3) | $mask;

        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }

        return (($data << 10) | $remainder) ^ self::FORMAT_MASK;
    }

    // ------------------------------------------------------------ Pénalité

    /** @param array<int, array<int, bool>> $modules */
    private static function penalty(array $modules): int
    {
        $size = count($modules);
        $penalty = 0;

        // Règle 1 : séries de cinq modules ou plus de même couleur.
        for ($line = 0; $line < $size; $line++) {
            $penalty += self::runPenalty($modules[$line]);

            $column = [];

            for ($row = 0; $row < $size; $row++) {
                $column[] = $modules[$row][$line];
            }

            $penalty += self::runPenalty($column);
        }

        // Règle 2 : chaque bloc 2 × 2 de même couleur.
        for ($row = 0; $row + 1 < $size; $row++) {
            for ($column = 0; $column + 1 < $size; $column++) {
                $first = $modules[$row][$column];

                if ($first === $modules[$row][$column + 1]
                    && $first === $modules[$row + 1][$column]
                    && $first === $modules[$row + 1][$column + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Règle 3 : motif de repère bordé de quatre modules clairs.
        $penalty += 40 * self::finderLikeCount($modules);

        // Règle 4 : écart de la proportion de modules noirs à 50 %.
        $dark = 0;

        foreach ($modules as $line) {
            foreach ($line as $module) {
                $dark += $module ? 1 : 0;
            }
        }

        $total = $size * $size;
        $penalty += 10 * intdiv(abs($dark * 20 - $total * 10), $total);

        return $penalty;
    }

    /** @param array<int, bool> $line */
    private static function runPenalty(array $line): int
    {
        $penalty = 0;
        $run = 1;
        $count = count($line);

        for ($i = 1; $i < $count; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;

                continue;
            }

            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }

            $run = 1;
        }

        if ($run >= 5) {
            $penalty += 3 + ($run - 5);
        }

        return $penalty;
    }

    /** @param array<int, array<int, bool>> $modules */
    private static function finderLikeCount(array $modules): int
    {
        $size = count($modules);
        $count = 0;

        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        for ($line = 0; $line < $size; $line++) {
            for ($start = 0; $start + 11 <= $size; $start++) {
                foreach ($patterns as $pattern) {
                    $inRow = true;
                    $inColumn = true;

                    for ($offset = 0; $offset < 11; $offset++) {
                        if ($modules[$line][$start + $offset] !== $pattern[$offset]) {
                            $inRow = false;
                        }

                        if ($modules[$start + $offset][$line] !== $pattern[$offset]) {
                            $inColumn = false;
                        }
                    }

                    $count += $inRow ? 1 : 0;
                    $count += $inColumn ? 1 : 0;
                }
            }
        }

        return $count;
    }
}

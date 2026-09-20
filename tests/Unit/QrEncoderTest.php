<?php

namespace Tests\Unit;

use App\Support\Qr\QrEncoder;
use Tests\TestCase;

/**
 * L'encodeur QR est la seule partie de l'application dont une erreur serait
 * silencieuse : un QR mal encodé ne plante pas, il ne se scanne pas. Ces tests
 * vérifient donc les propriétés mathématiques du standard, et les tableaux
 * contre les constantes publiées — pas seulement « le code tourne ».
 */
class QrEncoderTest extends TestCase
{
    /** Capacité du mode octets, niveau M (ISO/CEI 18004). */
    private const BYTE_CAPACITY = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
    ];

    /** Codewords de correction par bloc, niveau M. */
    private const EC_PER_BLOCK = [1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26];

    /** Nombre de blocs, niveau M. */
    private const BLOCK_COUNT = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4, 7 => 4, 8 => 4, 9 => 5, 10 => 5];

    /** Nombre total de codewords par version (constante du standard). */
    private const TOTAL_CODEWORDS = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346];

    public function test_les_tables_de_blocs_correspondent_au_standard(): void
    {
        foreach (self::BYTE_CAPACITY as $version => $capacity) {
            [$ecPerBlock, $blocks, $dataCodewords] = QrEncoder::blockStructure($version);

            $header = $version <= 9 ? 2 : 3;

            $this->assertSame($dataCodewords - $header, $capacity, "Capacité du mode octets, version $version");
            $this->assertSame(self::EC_PER_BLOCK[$version], $ecPerBlock, "Codewords de correction, version $version");
            $this->assertSame(self::BLOCK_COUNT[$version], $blocks, "Nombre de blocs, version $version");
            $this->assertSame(
                self::TOTAL_CODEWORDS[$version],
                $dataCodewords + $ecPerBlock * $blocks,
                "Total de codewords, version $version",
            );
        }
    }

    public function test_la_version_choisie_est_la_plus_petite_qui_contient_le_texte(): void
    {
        foreach (self::BYTE_CAPACITY as $version => $capacity) {
            $matrix = QrEncoder::encode(str_repeat('A', $capacity));

            $this->assertSame($version, $matrix->version, "Un texte de $capacity octets tient en version $version");
            $this->assertSame(17 + 4 * $version, $matrix->size());

            if ($version === 10) {
                continue;
            }

            // Un octet de plus, et la version suivante prend le relais : la
            // marge n'est jamais perdue, donc jamais d'échec à l'impression.
            $this->assertSame($version + 1, QrEncoder::encode(str_repeat('A', $capacity + 1))->version);
        }
    }

    public function test_les_codewords_de_correction_sont_un_vrai_code_de_reed_solomon(): void
    {
        foreach ([7, 10, 16, 18, 22, 26] as $degree) {
            $data = [];

            for ($i = 0; $i < 40; $i++) {
                $data[] = ($i * 37 + 11) % 256;
            }

            $codewords = array_merge($data, QrEncoder::errorCorrection($data, $degree));

            // Tout codeword d'un code de Reed-Solomon s'annule aux racines du
            // polynôme générateur : α^0, α^1, … α^(degree-1). C'est la
            // propriété qui permet au lecteur de corriger, et elle tombe si le
            // générateur démarre à la mauvaise puissance d'alpha.
            $this->assertTrue(
                $this->syndromesAreZero($codewords, $degree),
                "Syndromes nuls attendus pour $degree codewords de correction",
            );
        }
    }

    public function test_les_informations_de_format_forment_un_code_bch_valide(): void
    {
        $matrix = QrEncoder::encode('https://iziune.example/l/Ab12Cd34');
        $size = $matrix->size();

        $bits = 0;

        for ($i = 0; $i <= 5; $i++) {
            $bits |= ($matrix->isDark($i, 8) ? 1 : 0) << $i;
        }

        $bits |= ($matrix->isDark(7, 8) ? 1 : 0) << 6;
        $bits |= ($matrix->isDark(8, 8) ? 1 : 0) << 7;
        $bits |= ($matrix->isDark(8, 7) ? 1 : 0) << 8;

        for ($i = 9; $i < 15; $i++) {
            $bits |= ($matrix->isDark(8, 14 - $i) ? 1 : 0) << $i;
        }

        $word = $bits ^ 0x5412;

        // Cinq bits utiles : niveau de correction M (00) puis le masque.
        $this->assertSame($matrix->mask, $word >> 10, 'Les bits de format annoncent le masque appliqué');

        // Le reste de la division par le polynôme générateur du BCH(15,5) doit
        // être nul : c'est la définition d'un mot de code.
        $this->assertSame(0, $this->bchRemainder($word, 15, 0x537), 'Les bits de format sont un mot de code BCH');

        // La seconde copie, de l'autre côté du symbole, doit être identique.
        $second = 0;

        for ($i = 0; $i < 8; $i++) {
            $second |= ($matrix->isDark(8, $size - 1 - $i) ? 1 : 0) << $i;
        }

        for ($i = 8; $i < 15; $i++) {
            $second |= ($matrix->isDark($size - 15 + $i, 8) ? 1 : 0) << $i;
        }

        $this->assertSame($bits, $second, 'Les deux copies des informations de format sont identiques');
    }

    public function test_les_informations_de_version_sont_ecrites_a_partir_de_la_version_sept(): void
    {
        // 120 octets : au-delà des 106 de la version 6, en deçà des 122 de la 7.
        $matrix = QrEncoder::encode(str_repeat('A', 120));
        $size = $matrix->size();

        $this->assertSame(7, $matrix->version);

        $bits = 0;

        for ($i = 0; $i < 18; $i++) {
            $bits |= ($matrix->isDark(intdiv($i, 3), $size - 11 + $i % 3) ? 1 : 0) << $i;
        }

        $this->assertSame(7, $bits >> 12, 'Les informations de version annoncent la version 7');
        $this->assertSame(0, $this->bchRemainder($bits, 18, 0x1F25), 'Ce sont bien un mot de code BCH(18,6)');
    }

    public function test_les_motifs_de_repere_et_de_synchronisation_sont_en_place(): void
    {
        $matrix = QrEncoder::encode('https://iziune.example/l/Ab12Cd34');
        $size = $matrix->size();

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $column]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $distance = max(abs($x - 3), abs($y - 3));
                    $expected = $distance !== 2;

                    $this->assertSame(
                        $expected,
                        $matrix->isDark($row + $y, $column + $x),
                        "Repère en ($row, $column), module ($y, $x)",
                    );
                }
            }
        }

        // Motifs de synchronisation : alternance à partir de la ligne 8.
        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $matrix->isDark(6, $i));
            $this->assertSame($i % 2 === 0, $matrix->isDark($i, 6));
        }

        // Motif d'alignement du coin bas-droit : les trois autres croisements
        // tombent sur un repère, donc aucun n'est tracé.
        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $expected = max(abs($x - 2), abs($y - 2)) !== 1;

                // Centre à sept modules du bord, donc bloc de cinq modules de
                // size-9 à size-5.
                $this->assertSame(
                    $expected,
                    $matrix->isDark($size - 9 + $y, $size - 9 + $x),
                    "Motif d'alignement, module ($y, $x)",
                );
            }
        }

        // Le module toujours noir, à côté des informations de format.
        $this->assertTrue($matrix->isDark($size - 8, 8));
    }

    public function test_l_encodage_est_deterministe(): void
    {
        $first = QrEncoder::encode('https://iziune.example/l/Ab12Cd34');
        $second = QrEncoder::encode('https://iziune.example/l/Ab12Cd34');

        $this->assertSame($first->rows(), $second->rows());
        $this->assertSame($first->mask, $second->mask);
    }

    public function test_le_masque_retenu_est_bien_dans_les_huit_du_standard(): void
    {
        $matrix = QrEncoder::encode('https://iziune.example/l/Ab12Cd34');

        $this->assertGreaterThanOrEqual(0, $matrix->mask);
        $this->assertLessThanOrEqual(7, $matrix->mask);
    }

    /**
     * Les syndromes : pour i de 0 à degree-1, l'évaluation du polynôme aux
     * racines α^i doit donner zéro.
     *
     * @param  array<int, int>  $codewords
     */
    private function syndromesAreZero(array $codewords, int $degree): bool
    {
        for ($i = 0; $i < $degree; $i++) {
            $root = 1;

            for ($power = 0; $power < $i; $power++) {
                $root = $this->multiply($root, 0x02);
            }

            $value = 0;

            foreach ($codewords as $codeword) {
                $value = $this->multiply($value, $root) ^ $codeword;
            }

            if ($value !== 0) {
                return false;
            }
        }

        return true;
    }

    /** Reste de la division d'un mot par le polynôme générateur d'un code BCH. */
    private function bchRemainder(int $word, int $length, int $generator): int
    {
        $generatorBits = $this->bitLength($generator);

        for ($i = $length - 1; $i >= $generatorBits - 1; $i--) {
            if ((($word >> $i) & 1) === 1) {
                $word ^= $generator << ($i - ($generatorBits - 1));
            }
        }

        return $word;
    }

    private function bitLength(int $value): int
    {
        $length = 0;

        while ($value > 0) {
            $value >>= 1;
            $length++;
        }

        return $length;
    }

    /** Multiplication dans GF(256), polynôme primitif x^8+x^4+x^3+x^2+1. */
    private function multiply(int $x, int $y): int
    {
        $product = 0;

        for ($i = 7; $i >= 0; $i--) {
            $product = (($product << 1) ^ ((($product >> 7) & 1) * 0x11D)) & 0xFF;
            $product ^= (($y >> $i) & 1) * $x;
        }

        return $product & 0xFF;
    }
}

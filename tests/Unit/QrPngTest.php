<?php

namespace Tests\Unit;

use App\Support\Qr\QrEncoder;
use App\Support\Qr\QrMatrix;
use App\Support\Qr\QrPng;
use Tests\TestCase;

/**
 * Le PNG est écrit à la main : autant vérifier sa structure plutôt que de
 * supposer qu'un lecteur d'images l'acceptera.
 */
class QrPngTest extends TestCase
{
    public function test_le_png_a_la_structure_attendue(): void
    {
        $matrix = QrEncoder::encode('https://iziune.example/l/Ab12Cd34');
        $png = QrPng::render($matrix, 4);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        [$width, $height, $depth, $colorType] = $this->header($png);

        $side = ($matrix->size() + QrPng::QUIET_ZONE * 2) * 4;

        $this->assertSame($side, $width);
        $this->assertSame($height, $width);
        $this->assertSame(8, $depth);
        $this->assertSame(0, $colorType, 'Image en niveaux de gris');
    }

    public function test_les_blocs_du_png_ont_un_crc_valide(): void
    {
        $png = QrPng::render(QrEncoder::encode('https://exemple.test/l/Ab12Cd34'), 3);

        $types = [];
        $offset = 8;

        while ($offset < strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $crc = unpack('N', substr($png, $offset + 8 + $length, 4))[1];

            $this->assertSame(crc32($type.$data), $crc, "CRC du bloc $type");

            $types[] = $type;
            $offset += 12 + $length;
        }

        $this->assertSame(['IHDR', 'IDAT', 'IEND'], $types);
        $this->assertSame(strlen($png), $offset, 'Aucun octet en trop après IEND');
    }

    public function test_les_donnees_decompressent_en_autant_de_pixels_que_annonce(): void
    {
        $matrix = QrEncoder::encode('https://exemple.test/l/Ab12Cd34');
        $png = QrPng::render($matrix, 3);

        [, $width] = $this->header($png);

        $raw = gzuncompress(substr($png, strpos($png, 'IDAT') + 4, $this->chunkLength($png, 'IDAT')));

        $this->assertSame($width * ($width + 1), strlen($raw), 'Une ligne = un octet de filtre + un octet par pixel');
    }

    public function test_la_marge_blanche_entoure_le_symbole(): void
    {
        $matrix = QrEncoder::encode('https://exemple.test/l/Ab12Cd34');
        $scale = 3;

        $png = QrPng::render($matrix, $scale);
        [, $width] = $this->header($png);

        $raw = gzuncompress(substr($png, strpos($png, 'IDAT') + 4, $this->chunkLength($png, 'IDAT')));

        $pixel = function (int $x, int $y) use ($raw, $width): int {
            return ord($raw[$y * ($width + 1) + 1 + $x]);
        };

        // Première ligne : entièrement blanche (marge de quatre modules).
        for ($x = 0; $x < $width; $x++) {
            $this->assertSame(0xFF, $pixel($x, 0));
        }

        // Le cœur du repère supérieur gauche est noir, même après la marge.
        $offset = (QrPng::QUIET_ZONE + 3) * $scale;

        $this->assertSame(0x00, $pixel($offset, $offset));

        // Et un module clair du repère (distance 2 du centre) reste blanc.
        $light = (QrPng::QUIET_ZONE + 5) * $scale;

        $this->assertSame(0xFF, $pixel($light, $offset));
    }

    public function test_la_data_uri_contient_le_png(): void
    {
        $matrix = QrEncoder::encode('https://exemple.test/l/Ab12Cd34');

        $uri = QrPng::dataUri('https://exemple.test/l/Ab12Cd34', 4);

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame(
            base64_encode(QrPng::render($matrix, 4)),
            substr($uri, strlen('data:image/png;base64,')),
        );
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} */
    private function header(string $png): array
    {
        $ihdr = substr($png, 16, 13);

        return array_values(unpack('Nwidth/Nheight/Cdepth/Ctype', $ihdr));
    }

    private function chunkLength(string $png, string $type): int
    {
        $start = strpos($png, $type) - 4;

        return unpack('N', substr($png, $start, 4))[1];
    }
}

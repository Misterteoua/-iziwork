<?php

namespace Tests\Unit;

use App\Support\ShortCode;
use Tests\TestCase;

class ShortCodeTest extends TestCase
{
    public function test_un_code_a_la_longueur_prevue(): void
    {
        $this->assertSame(8, strlen(ShortCode::generate()));
    }

    public function test_un_code_evite_les_caracteres_ambigus(): void
    {
        // Ni O/0 ni I/1/l : un code se dicte au téléphone, puis se retape.
        for ($i = 0; $i < 200; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[O0I1l]/', ShortCode::generate());
        }
    }

    public function test_les_codes_sont_differents(): void
    {
        $codes = [];

        for ($i = 0; $i < 500; $i++) {
            $codes[] = ShortCode::generate();
        }

        $this->assertCount(500, array_unique($codes));
    }

    public function test_le_code_correspond_au_motif_accepte_par_la_route(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression(
                '/^'.ShortCode::PATTERN.'$/',
                ShortCode::generate(),
            );
        }
    }
}

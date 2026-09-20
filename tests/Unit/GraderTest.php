<?php

namespace Tests\Unit;

use App\Models\Grader;
use App\Support\QuizReference;
use App\Support\ShortCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ce qu'un correcteur reçoit, et ce que cela garantit.
 *
 * Une référence de dix caractères sans O/0 ni I/1/L, un lien de huit : les deux
 * se recopient et se dictent. Le test le plus important du fichier est celui de
 * l'échéance : c'est elle qui doit fermer l'accès, sans aucune tâche planifiée.
 */
class GraderTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_correcteur_recoit_une_reference_et_un_lien_tires_au_sort(): void
    {
        $grader = Grader::createFor('Awa Kouassi', 'AWA@Test.com', Carbon::now()->addDays(3), null);

        $this->assertSame(QuizReference::LENGTH, strlen($grader->reference));
        $this->assertSame(ShortCode::LENGTH, strlen($grader->link_code));

        // Alphabet sans caractères ambigus, comme les références d'étudiants :
        // une référence de correcteur se dicte aussi au téléphone.
        $this->assertSame(1, preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]+$/', $grader->reference));

        // L'email est normalisé : la comparaison à la connexion ne doit pas
        // dépendre de la casse saisie par l'administrateur.
        $this->assertSame('awa@test.com', $grader->email);
        $this->assertSame('Awa Kouassi', $grader->name);
    }

    public function test_deux_correcteurs_ne_partagent_ni_reference_ni_lien(): void
    {
        $first = Grader::createFor('Awa', 'awa@test.com', null, null);
        $second = Grader::createFor('Boris', 'boris@test.com', null, null);

        $this->assertNotSame($first->reference, $second->reference);
        $this->assertNotSame($first->link_code, $second->link_code);
    }

    public function test_le_lien_est_l_adresse_de_l_espace_de_correction(): void
    {
        $grader = Grader::createFor('Awa', 'awa@test.com', null, null);

        $this->assertStringEndsWith('/correction/'.$grader->link_code, $grader->link());
    }

    public function test_sans_echeance_la_mission_ne_se_ferme_pas(): void
    {
        $grader = Grader::createFor('Awa', 'awa@test.com', null, null);

        $this->assertFalse($grader->isExpired());
        $this->assertNull($grader->daysRemaining());
    }

    public function test_l_echeance_se_ferme_d_elle_meme_le_moment_venu(): void
    {
        $grader = Grader::createFor('Awa', 'awa@test.com', Carbon::now()->addDay(), null);

        $this->assertFalse($grader->isExpired());

        // Aucune tâche planifiée n'est nécessaire : c'est la lecture de
        // l'échéance à chaque requête qui ferme l'accès.
        Carbon::setTestNow(Carbon::now()->addDays(2));

        $this->assertTrue($grader->isExpired());

        Carbon::setTestNow();
    }

    public function test_regenerer_le_lien_invalide_l_ancien(): void
    {
        $grader = Grader::createFor('Awa', 'awa@test.com', null, null);
        $ancien = $grader->link_code;

        $grader->rotateLink();
        $grader->refresh();

        $this->assertNotSame($ancien, $grader->link_code);
        $this->assertSame(ShortCode::LENGTH, strlen($grader->link_code));
    }

    public function test_la_reference_est_la_meme_famille_que_celle_des_etudiants(): void
    {
        // Deux références, une seule façon de les dicter : la normalisation qui
        // sert aux étudiants doit accepter celle d'un correcteur.
        $grader = Grader::createFor('Awa', 'awa@test.com', null, null);

        $this->assertSame($grader->reference, QuizReference::normalize(mb_strtolower($grader->reference)));
    }
}

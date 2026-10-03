<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Grader;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mise en page de la fiche de mission d'un correcteur.
 *
 * Même identité visuelle que les récapitulatifs : logo de l'établissement en
 * haut, logo Iziwork dans un pied de page répété. Ce fichier vérifie l'habillage,
 * et surtout que le point de sécurité de la fiche n'a pas bougé — la référence
 * ne s'imprime que si on le demande, parce qu'une fiche portant le lien **et** la
 * clé ouvrirait l'accès à elle seule.
 */
class GraderMissionPdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00'));

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $this->quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-MISSION-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['duration_minutes' => 30],
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    /**
     * Affecte un correcteur par la route réelle : c'est elle qui pose le lien
     * personnel et la référence, comme en production.
     */
    private function assign(int $days = 3): Grader
    {
        $this->post(route('admin.quizzes.graders.store', $this->quiz), [
            'name' => 'Awa Diallo',
            'email' => 'awa@correcteur.test',
            'days' => $days,
        ])->assertSessionHasNoErrors();

        return $this->quiz->graders()->where('email', 'awa@correcteur.test')->firstOrFail();
    }

    private function render(Grader $grader, bool $withReference): string
    {
        return view('admin.quizzes.grader-mission-pdf', [
            'quiz' => $this->quiz,
            'grader' => $grader,
            'link' => $grader->link(),
            'qr' => QrPng::dataUri($grader->link(), 10),
            'withReference' => $withReference,
        ])->render();
    }

    // ------------------------------------------------------------------ Logos

    public function test_la_fiche_porte_le_logo_de_l_etablissement_en_haut(): void
    {
        $html = $this->render($this->assign(), withReference: false);

        $this->assertStringContainsString('alt="EFSC"', $html);
        $this->assertLessThan(
            strpos($html, 'Fiche de mission'),
            strpos($html, 'alt="EFSC"'),
            'Le logo doit précéder le titre de la fiche.'
        );
    }

    public function test_la_fiche_porte_le_logo_iziwork_en_bas_de_page(): void
    {
        $html = $this->render($this->assign(), withReference: false);

        $this->assertStringContainsString('alt="Iziwork"', $html);
        $this->assertStringContainsString('page-footer', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('Document généré automatiquement par Iziwork', $html);
        $this->assertStringContainsString('Ne transmettez ce document', $html);
    }

    public function test_la_fiche_se_genere_toujours(): void
    {
        $grader = $this->assign();

        $response = $this->get(route('admin.quizzes.graders.mission', [$this->quiz, $grader]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    // ------------------------------------------------- Contenu préservé

    public function test_la_refonte_conserve_toutes_les_informations_de_la_mission(): void
    {
        $grader = $this->assign();
        $html = $this->render($grader, withReference: true);

        $this->assertStringContainsString('Awa Diallo', $html);
        $this->assertStringContainsString('awa@correcteur.test', $html);
        $this->assertStringContainsString('Examen de Chimie', $html);
        $this->assertStringContainsString('Votre mission', $html);
        $this->assertStringContainsString('Votre lien personnel', $html);
        $this->assertStringContainsString('Comment procéder', $html);
        $this->assertStringContainsString($grader->link(), $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('QR code du lien de correction', $html);
    }

    public function test_la_reference_ne_s_imprime_que_si_on_le_demande(): void
    {
        // Le point de sécurité de la fiche : sans le drapeau, le document ne
        // porte pas la clé d'accès.
        $grader = $this->assign();

        $sansReference = $this->render($grader, withReference: false);
        $avecReference = $this->render($grader, withReference: true);

        $this->assertStringNotContainsString($grader->reference, $sansReference);
        $this->assertStringContainsString('Référence transmise séparément', $sansReference);

        $this->assertStringContainsString($grader->reference, $avecReference);
        $this->assertStringContainsString('Remise en main propre', $avecReference);
    }

    public function test_l_echeance_est_rappelee(): void
    {
        $grader = $this->assign(days: 3);
        $html = $this->render($grader, withReference: false);

        $this->assertStringContainsString($grader->expires_at->format('d/m/Y à H:i'), $html);
        $this->assertStringContainsString("l'accès se ferme automatiquement", $html);
    }

    public function test_une_mission_sans_echeance_le_dit(): void
    {
        $grader = $this->assign(days: 3);

        // Une mission peut se retrouver sans échéance (donnée effacée, reprise
        // d'une base ancienne) : la fiche doit alors le dire, pas rester muette.
        $grader->update(['expires_at' => null]);

        $html = $this->render($grader->refresh(), withReference: false);

        $this->assertStringContainsString('Aucune échéance fixée', $html);
    }
}

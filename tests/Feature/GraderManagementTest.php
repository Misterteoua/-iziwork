<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Grader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'administrateur confie des copies à un correcteur externe.
 *
 * Ce que ces tests protègent : la création (une référence et un lien engendrés,
 * jamais choisis), la lisibilité de ce qu'on lui transmet (dont le QR code), la
 * fermeture de l'accès à l'échéance, et l'étanchéité entre évaluations — le lien
 * d'un correcteur ne donne pas accès à la fiche d'un autre.
 */
class GraderManagementTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));

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

        $this->quiz = $this->makeQuiz('Examen de Chimie', 'JETON-CHIMIE');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeQuiz(string $title, string $token): Form
    {
        return Form::create([
            'title' => $title,
            'token' => $token,
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => true],
            'created_by' => $this->admin->id,
        ]);
    }

    private function assign(string $name = 'Awa Kouassi', string $email = 'awa@test.com', int $days = 3, ?Form $quiz = null): Grader
    {
        $quiz ??= $this->quiz;

        $this->post(route('admin.quizzes.graders.store', $quiz), [
            'name' => $name,
            'email' => $email,
            'days' => $days,
        ])->assertRedirect(route('admin.quizzes.graders', $quiz))->assertSessionHasNoErrors();

        return $quiz->graders()->where('email', mb_strtolower($email))->firstOrFail();
    }

    // ---------------------------------------------------------------- Accès

    public function test_un_visiteur_ne_peut_pas_gerer_les_correcteurs(): void
    {
        $this->flushSession();

        $this->get(route('admin.quizzes.graders', $this->quiz))->assertRedirect('/login');
        $this->post(route('admin.quizzes.graders.store', $this->quiz), [])->assertRedirect('/login');
    }

    public function test_un_depot_de_travaux_n_a_pas_de_correcteurs(): void
    {
        $form = Form::create([
            'title' => 'Dépôt de rapports',
            'token' => 'JETON-DEPOT',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->get(route('admin.quizzes.graders', $form))->assertNotFound();
    }

    // -------------------------------------------------------------- Création

    public function test_l_administrateur_assigne_un_correcteur(): void
    {
        $grader = $this->assign();

        $this->assertSame('Awa Kouassi', $grader->name);
        $this->assertSame(10, strlen($grader->reference));
        $this->assertSame(8, strlen($grader->link_code));
        $this->assertTrue($grader->forms()->whereKey($this->quiz->id)->exists());

        // Fin de journée : un délai de trois jours laisse travailler jusqu'au
        // soir du troisième.
        $this->assertSame(
            Carbon::now()->addDays(3)->endOfDay()->toDateTimeString(),
            $grader->expires_at->toDateTimeString()
        );
    }

    public function test_la_page_montre_le_lien_son_qr_code_et_la_reference(): void
    {
        $grader = $this->assign();

        $response = $this->get(route('admin.quizzes.graders', $this->quiz));

        $response->assertOk()
            ->assertSee($grader->reference)
            ->assertSee($grader->link())
            ->assertSee('Fiche de mission (PDF)')
            // Le QR est calculé côté serveur : il s'affiche sans JavaScript.
            ->assertSee('data:image/png;base64,', false);
    }

    public function test_un_email_deja_connu_reutilise_le_meme_correcteur(): void
    {
        $first = $this->assign();
        $other = $this->makeQuiz('Examen de Physique', 'JETON-PHYSIQUE');

        $this->post(route('admin.quizzes.graders.store', $other), [
            'name' => 'Awa Kouassi',
            'email' => 'AWA@test.com',
            'days' => 5,
        ])->assertSessionHasNoErrors();

        // Un seul compte, un seul lien, une seule référence à retenir.
        $this->assertSame(1, Grader::where('email', 'awa@test.com')->count());

        $first->refresh();

        $this->assertTrue($first->forms()->whereKey($other->id)->exists());
        $this->assertSame(10, strlen($first->reference));
        $this->assertSame(2, $first->forms()->count());
    }

    public function test_l_email_et_le_nom_sont_valides(): void
    {
        $this->post(route('admin.quizzes.graders.store', $this->quiz), [
            'name' => '',
            'email' => 'pas-un-email',
            'days' => 0,
        ])->assertSessionHasErrors(['name', 'email', 'days']);

        $this->assertSame(0, Grader::count());
    }

    // ------------------------------------------------------------ Transmettre

    public function test_regenerer_le_lien_invalide_l_ancien(): void
    {
        $grader = $this->assign();
        $ancien = $grader->link_code;

        $this->post(route('admin.quizzes.graders.link', [$this->quiz, $grader]))
            ->assertRedirect(route('admin.quizzes.graders', $this->quiz))
            ->assertSessionHasNoErrors();

        $grader->refresh();

        $this->assertNotSame($ancien, $grader->link_code);

        // L'ancien lien ne trouve plus personne : un correcteur ne peut pas
        // continuer à entrer avec une adresse révoquée.
        $this->get('/correction/'.$ancien)->assertNotFound();
        $this->get('/correction/'.$grader->link_code)->assertOk();
    }

    public function test_la_fiche_de_mission_porte_le_qr_code_et_l_adresse(): void
    {
        $grader = $this->assign();

        $response = $this->get(route('admin.quizzes.graders.mission', [$this->quiz, $grader]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        // Le contenu exact est vérifié par le rendu de la vue : DomPDF comprime
        // le PDF, on n'y lit pas un texte.
        $rendered = view('admin.quizzes.grader-mission-pdf', [
            'quiz' => $this->quiz,
            'grader' => $grader,
            'link' => $grader->link(),
            'qr' => \App\Support\Qr\QrPng::dataUri($grader->link(), 10),
            'withReference' => false,
        ])->render();

        $this->assertStringContainsString($grader->link(), $rendered);
        $this->assertStringContainsString('data:image/png;base64,', $rendered);
        $this->assertStringContainsString('Examen de Chimie', $rendered);
    }

    /**
     * Le point de sécurité de la fiche : elle ne porte pas la référence par
     * défaut, sinon le document ouvrirait l'accès à lui seul.
     */
    public function test_la_fiche_ne_porte_la_reference_que_si_on_le_demande(): void
    {
        $grader = $this->assign();

        $sansReference = view('admin.quizzes.grader-mission-pdf', [
            'quiz' => $this->quiz,
            'grader' => $grader,
            'link' => $grader->link(),
            'qr' => \App\Support\Qr\QrPng::dataUri($grader->link(), 10),
            'withReference' => false,
        ])->render();

        $avecReference = view('admin.quizzes.grader-mission-pdf', [
            'quiz' => $this->quiz,
            'grader' => $grader,
            'link' => $grader->link(),
            'qr' => \App\Support\Qr\QrPng::dataUri($grader->link(), 10),
            'withReference' => true,
        ])->render();

        $this->assertStringNotContainsString($grader->reference, $sansReference);
        $this->assertStringContainsString($grader->reference, $avecReference);
    }

    // --------------------------------------------------------------- Échéance

    public function test_prolonger_ajoute_du_temps_sans_jamais_en_retirer(): void
    {
        $grader = $this->assign(days: 1);

        $this->patch(route('admin.quizzes.graders.extend', [$this->quiz, $grader]), ['days' => 3])
            ->assertRedirect(route('admin.quizzes.graders', $this->quiz));

        $grader->refresh();

        $this->assertSame(
            Carbon::now()->addDay()->endOfDay()->addDays(3)->endOfDay()->toDateTimeString(),
            $grader->expires_at->toDateTimeString()
        );
        $this->assertFalse($grader->isExpired());
    }

    public function test_une_echeance_depassee_se_prolonge_a_partir_de_maintenant(): void
    {
        $grader = $this->assign(days: 1);

        Carbon::setTestNow(Carbon::now()->addDays(10));

        $this->assertTrue($grader->refresh()->isExpired());

        $this->patch(route('admin.quizzes.graders.extend', [$this->quiz, $grader]), ['days' => 2])
            ->assertSessionHasNoErrors();

        $grader->refresh();

        $this->assertFalse($grader->isExpired());
        $this->assertSame(
            Carbon::now()->addDays(2)->endOfDay()->toDateTimeString(),
            $grader->expires_at->toDateTimeString()
        );
    }

    public function test_suspendre_ferme_l_acces_sans_effacer_la_personne(): void
    {
        $grader = $this->assign();
        $grader->update(['last_seen_at' => Carbon::now(), 'last_ip' => '10.0.0.9']);

        $this->patch(route('admin.quizzes.graders.suspend', [$this->quiz, $grader]))
            ->assertSessionHasNoErrors();

        $grader->refresh();

        $this->assertTrue($grader->isExpired());

        // Rien n'est supprimé : les notes déjà posées doivent garder leur auteur.
        $this->assertDatabaseHas('graders', ['id' => $grader->id, 'name' => 'Awa Kouassi']);
        $this->assertSame('10.0.0.9', $grader->last_ip);
    }

    public function test_une_prolongation_rouvre_l_acces_apres_une_suspension(): void
    {
        $grader = $this->assign();

        $this->patch(route('admin.quizzes.graders.suspend', [$this->quiz, $grader]));

        $this->assertTrue($grader->refresh()->isExpired());

        $this->patch(route('admin.quizzes.graders.extend', [$this->quiz, $grader]), ['days' => 1]);

        $this->assertFalse($grader->refresh()->isExpired());
    }

    // ------------------------------------------------------------- Périmètre

    public function test_retirer_une_evaluation_ne_touche_pas_aux_autres(): void
    {
        $grader = $this->assign();
        $other = $this->makeQuiz('Examen de Physique', 'JETON-PHYSIQUE');

        $this->post(route('admin.quizzes.graders.store', $other), [
            'name' => 'Awa Kouassi',
            'email' => 'awa@test.com',
            'days' => 3,
        ]);

        $this->delete(route('admin.quizzes.graders.detach', [$this->quiz, $grader]))
            ->assertSessionHasNoErrors();

        $grader->refresh();

        $this->assertFalse($grader->forms()->whereKey($this->quiz->id)->exists());
        $this->assertTrue($grader->forms()->whereKey($other->id)->exists());

        // Le correcteur reste : son lien et sa référence continuent de servir.
        $this->assertDatabaseHas('graders', ['id' => $grader->id]);
    }

    public function test_la_fiche_d_un_correcteur_ne_s_ouvre_pas_depuis_une_autre_evaluation(): void
    {
        $grader = $this->assign();
        $other = $this->makeQuiz('Examen de Physique', 'JETON-PHYSIQUE');

        $this->get(route('admin.quizzes.graders.mission', [$other, $grader]))->assertNotFound();
        $this->post(route('admin.quizzes.graders.link', [$other, $grader]))->assertNotFound();
        $this->delete(route('admin.quizzes.graders.detach', [$other, $grader]))->assertNotFound();

        $this->assertSame(1, DB::table('grader_assignments')->count());
    }

    public function test_les_resultats_donnent_acces_aux_correcteurs(): void
    {
        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee(route('admin.quizzes.graders', $this->quiz));
    }
}

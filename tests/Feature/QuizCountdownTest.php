<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Compte à rebours avant l'ouverture d'une évaluation.
 *
 * Deux exigences, et la seconde compte autant que la première :
 *
 *   1. une épreuve qui n'a pas encore commencé annonce l'heure d'ouverture et
 *      décompte les secondes restantes, pour que la page se recharge d'elle-même
 *      à l'échéance et laisse le candidat entrer ;
 *   2. la règle serveur ne bouge pas — avant l'heure, le démarrage reste refusé.
 *      Un compte à rebours est un confort d'affichage, jamais une autorisation.
 *
 * Le dernier test est le plus important : il verrouille le fait qu'aucun de ces
 * ajouts n'ouvre l'épreuve par avance.
 */
class QuizCountdownTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00:00'));

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function quiz(array $attributes = []): Form
    {
        return Form::create(array_merge([
            'title' => 'Examen de Comptabilité',
            'token' => 'JETON-COMPTE-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => false],
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function question(Form $quiz): FormField
    {
        return $quiz->fields()->create([
            'field_label' => 'Question unique',
            'field_type' => 'radio',
            'required' => true,
            'order' => 1,
            'options' => ['Un', 'Deux'],
            'correct_answer' => [1],
            'points' => 1,
        ]);
    }

    // ------------------------------------------------------ Ouverture à venir

    public function test_la_page_d_acces_compte_les_secondes_avant_l_ouverture(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 08:01:30')]);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            // Le message d'origine reste, mot pour mot : rien n'est retiré aux
            // autres cas de fermeture.
            ->assertSee('pas ouverte')
            // Et le formulaire reste caché : on attend, on ne commence pas.
            ->assertDontSee('Commencer')
            ->assertSee('id="quiz-open-countdown"', false)
            ->assertSee('id="quiz-open-clock"', false)
            // Quatre-vingt-dix secondes exactement : la durée vient du serveur,
            // pas du navigateur.
            ->assertSee('data-seconds="90"', false)
            // Le compteur de départ est déjà rendu par le serveur : quatre-vingt-
            // dix secondes s'écrivent 00:01:30, jamais « --:--:-- ».
            ->assertSee('tabular-nums">00:01:30</p>', false)
            ->assertSee('12/10/2026 à 08:01');

        $this->assertSame(90, $quiz->quizOpensInSeconds());
    }

    public function test_le_compte_a_rebours_se_cale_sur_l_horloge_du_serveur(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 08:45:10')]);
        $this->question($quiz);

        // Le serveur avance de dix minutes : le décompte suit, sans que le
        // navigateur ait son mot à dire.
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:15:00'));

        $this->assertSame(1810, $quiz->quizOpensInSeconds());

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('data-seconds="1810"', false);
    }

    public function test_a_l_heure_dite_le_compte_a_rebours_disparait_et_l_epreuve_demarre(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 08:01:00')]);
        $this->question($quiz);

        // Une seconde après l'ouverture : il n'y a plus rien à attendre.
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:01:01'));

        $this->assertNull($quiz->quizOpensAt());
        $this->assertNull($quiz->quizOpensInSeconds());

        // C'est exactement ce que montre la page après son rechargement
        // automatique : le décompte a disparu, le bouton est là.
        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertDontSee('id="quiz-open-countdown"', false)
            ->assertSee('Commencer');

        $this->post(route('quiz.begin', $quiz->token), ['student_name' => 'Jean'])
            ->assertRedirect(route('quiz.question', $quiz->token));

        $attempt = $quiz->attempts()->firstOrFail();

        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $attempt->status);
        $this->assertSame('Jean', $attempt->student_name);
    }

    // ------------------------------------------------- Autres cas de fermeture

    public function test_aucun_compte_a_rebours_sur_une_evaluation_desactivee(): void
    {
        // Désactivée par l'administration : rien ne s'ouvrira à l'heure dite.
        // Afficher un compteur promettrait une ouverture qui n'aura pas lieu.
        $quiz = $this->quiz([
            'status' => 'inactive',
            'open_date' => Carbon::parse('2026-10-12 09:00:00'),
        ]);
        $this->question($quiz);

        $this->assertNull($quiz->quizOpensAt());
        $this->assertNull($quiz->quizOpensInSeconds());

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('pas ouverte')
            ->assertDontSee('id="quiz-open-countdown"', false);
    }

    public function test_aucun_compte_a_rebours_sur_une_evaluation_deja_fermee(): void
    {
        $quiz = $this->quiz(['close_date' => Carbon::parse('2026-10-12 07:00:00')]);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('pas ouverte')
            ->assertDontSee('id="quiz-open-countdown"', false);
    }

    public function test_aucun_compte_a_rebours_quand_l_evaluation_est_deja_ouverte(): void
    {
        // Le cas nominal : une épreuve ouverte depuis la veille n'a rien à
        // décompter, et sa page d'accès est celle d'avant.
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-11 09:00:00')]);
        $this->question($quiz);

        $this->assertNull($quiz->quizOpensInSeconds());

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertDontSee('id="quiz-open-countdown"', false)
            ->assertSee('Commencer');
    }

    // ------------------------------------------------------- Format du compteur

    public function test_au_dela_de_vingt_quatre_heures_le_compte_a_rebours_affiche_les_jours(): void
    {
        // Trois jours et quatre heures à attendre : un total d'heures empilées
        // (« 72:12:07 ») ne se lit plus au premier coup d'œil, on compte donc en
        // jours — c'est le cas d'un lien distribué une semaine à l'avance.
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-15 12:12:07')]);
        $this->question($quiz);

        $this->assertSame(274327, $quiz->quizOpensInSeconds());

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('tabular-nums">3 j 04:12:07</p>', false)
            // Le même instant en heures empilées : c'est exactement ce que
            // l'affichage ne doit plus produire.
            ->assertDontSee('tabular-nums">76:12:07</p>', false);
    }

    public function test_le_format_horaire_reste_intact_sous_vingt_quatre_heures(): void
    {
        // 23 h 59 min 59 s : le format d'origine, sans mention de jour. C'est le
        // cas courant, il ne doit pas changer d'apparence.
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-13 07:59:59')]);
        $this->question($quiz);

        $this->assertSame(86399, $quiz->quizOpensInSeconds());

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('tabular-nums">23:59:59</p>', false);
    }

    public function test_vingt_quatre_heures_exactes_basculent_en_jours(): void
    {
        // La borne : à partir de vingt-quatre heures pile, les jours apparaissent.
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-13 08:00:00')]);
        $this->question($quiz);

        $this->assertSame(86400, $quiz->quizOpensInSeconds());

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('tabular-nums">1 j 00:00:00</p>', false);
    }

    // ------------------------------------------- Ce qu'un lecteur d'écran lit

    public function test_le_compte_a_rebours_n_est_plus_annonce_chaque_seconde(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 08:30:00')]);
        $this->question($quiz);

        $html = $this->get(route('quiz.start', $quiz->token))->assertOk()->getContent();

        // Le compteur change chaque seconde : exposé tel quel, il faisait annoncer
        // l'heure à chaque battement. Il est donc masqué aux lecteurs d'écran...
        $clock = strpos($html, 'id="quiz-open-clock"');
        $this->assertNotFalse($clock);
        $this->assertStringContainsString('aria-hidden="true"', substr($html, $clock, 120));

        // ...et la carte qui le contient n'est plus une région « live » : c'est
        // cette région, et non le compteur, qui causait les annonces.
        $card = strpos($html, 'id="quiz-open-countdown"');
        $this->assertNotFalse($card);
        $this->assertStringNotContainsString('aria-live', substr($html, $card, $clock - $card));
        $this->assertStringNotContainsString('role="status"', substr($html, $card, $clock - $card));
    }

    public function test_le_temps_restant_est_dit_en_toutes_lettres(): void
    {
        // Une heure et quart : c'est cette phrase, et non « 01:15:00 », que lit un
        // lecteur d'écran — et elle est là dès l'affichage, sans JavaScript.
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 09:15:00')]);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            // La région « live », et rien d'autre du décompte.
            ->assertSee('<p id="quiz-open-announce" class="sr-only" role="status" aria-live="polite">', false)
            ->assertSee('Temps restant avant l\'ouverture : 1 heure 15 minutes.');
    }

    public function test_l_annonce_du_temps_restant_s_arrete_a_deux_unites(): void
    {
        // Trois jours et quatre heures : au-delà de deux unités, l'annonce se perd
        // dans les détails — les minutes d'une attente de trois jours n'apprennent
        // rien à personne.
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-15 12:12:07')]);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('Temps restant avant l\'ouverture : 3 jours 4 heures.')
            // Le décompte visuel, lui, garde son format compact.
            ->assertSee('tabular-nums">3 j 04:12:07</p>', false);
    }

    // --------------------------------------------------------- Garde serveur

    public function test_le_serveur_refuse_toujours_de_demarrer_avant_l_heure(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 08:30:00')]);
        $this->question($quiz);

        // Le compte à rebours est un confort d'affichage : le serveur, lui,
        // garde l'épreuve fermée jusqu'à l'heure — même si le formulaire est
        // posté directement, sans passer par la page.
        $this->post(route('quiz.begin', $quiz->token), ['student_name' => 'Jean'])
            ->assertSessionHas('error');

        $this->assertSame(0, $quiz->attempts()->count());
    }
}

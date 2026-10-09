<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Annonce de l'ouverture : la page qui vient de s'ouvrir le dit.
 *
 * Le signal sonore et le titre qui clignote appartiennent au navigateur. Ce que
 * ces tests vérifient, c'est ce que le serveur doit rendre pour qu'ils
 * fonctionnent : la trace que la page d'attente laissera avant de se recharger,
 * l'indice qui explique comment armer le son, et le bandeau d'ouverture — rendu
 * masqué, pour n'apparaître qu'à celui qui vient réellement d'attendre.
 */
class QuizOpeningNoticeTest extends TestCase
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
            'title' => 'Examen de Statistiques',
            'token' => 'JETON-OUVERTURE-'.uniqid(),
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

    // -------------------------------------------------- Le signal sonore annoncé

    public function test_la_page_d_attente_explique_comment_armer_le_signal_sonore(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 09:00:00')]);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('id="quiz-sound-state"', false)
            ->assertSee('Cliquez ou touchez cette page', false)
            // Le texte affiché une fois le signal armé, et celui du navigateur qui
            // ne sait pas jouer de son.
            ->assertSee('data-active="Signal sonore activé', false)
            ->assertSee('data-unavailable="Signal sonore indisponible', false)
            // La trace qui fera parler la page suivante, et le signal que le
            // script attend — actif par défaut.
            ->assertSee('data-notice-key="quiz_opened.'.$quiz->id.'"', false)
            ->assertSee('data-sound="1"', false);
    }

    public function test_le_signal_sonore_retire_disparait_de_la_page_d_attente(): void
    {
        // L'épreuve a retiré le signal : la page n'invite plus à toucher l'écran
        // pour un son qui ne viendra pas, et dit au script de ne pas l'attendre.
        $quiz = $this->quiz([
            'open_date' => Carbon::parse('2026-10-12 09:00:00'),
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
                'opening_sound' => false,
            ],
        ]);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('data-sound="0"', false)
            ->assertDontSee('id="quiz-sound-state"', false)
            ->assertDontSee('Cliquez ou touchez cette page', false)
            // Le reste de l'attente ne bouge pas : le décompte, sa durée, et la
            // trace qui fera parler la page ouverte.
            ->assertSee('id="quiz-open-countdown"', false)
            ->assertSee('data-seconds="3600"', false)
            ->assertSee('data-notice-key="quiz_opened.'.$quiz->id.'"', false);
    }

    public function test_le_signal_sonore_retire_ne_retire_rien_d_autre(): void
    {
        // Le réglage ne touche que le son : la page se recharge toujours à
        // l'heure dite, et le bandeau d'ouverture est toujours là — masqué, pour
        // n'apparaître qu'à celui qui vient réellement d'attendre.
        $quiz = $this->quiz([
            'open_date' => Carbon::parse('2026-10-12 09:00:00'),
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
                'opening_sound' => false,
            ],
        ]);
        $this->question($quiz);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:01'));

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('<div id="quiz-opened-notice"', false)
            ->assertSee('Vous pouvez commencer maintenant.')
            ->assertSee('Commencer');
    }

    public function test_la_page_ouverte_rend_le_bandeau_masque(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 09:00:00')]);
        $this->question($quiz);

        // Une seconde après l'ouverture : la page d'accueil des étudiants.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:01'));

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('Commencer')
            // Rendue masquée : elle ne s'affiche que si la page d'attente a laissé
            // sa trace, jamais pour une visite ordinaire.
            ->assertSee('<div id="quiz-opened-notice" data-notice-key="quiz_opened.'.$quiz->id.'" hidden', false)
            ->assertSee('épreuve vient d', false)
            ->assertSee('Vous pouvez commencer maintenant.');
    }

    // ----------------------------------------------------------- Rien à annoncer

    public function test_le_bandeau_n_est_pas_rendu_sur_la_page_d_attente(): void
    {
        $quiz = $this->quiz(['open_date' => Carbon::parse('2026-10-12 09:00:00')]);
        $this->question($quiz);

        // Personne n'attend encore : il n'y a rien à annoncer, et la page ne doit
        // même pas porter le bandeau.
        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertDontSee('id="quiz-opened-notice"', false);
    }

    public function test_aucun_bandeau_sur_une_evaluation_fermee(): void
    {
        $quiz = $this->quiz(['status' => 'inactive']);
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('pas ouverte')
            ->assertDontSee('id="quiz-opened-notice"', false);
    }
}

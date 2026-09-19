<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Parcours complet d'un étudiant : référence, chrono serveur, navigation
 * linéaire, correction automatique et récapitulatif.
 */
class QuizAttemptFlowTest extends TestCase
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

        $this->quiz = $this->makeQuiz();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function makeQuiz(array $attributes = [], array $settings = []): Form
    {
        return Form::create(array_merge([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-QUIZ-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => array_merge([
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => true,
            ], $settings),
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, int>  $correct
     */
    private function question(array $options = ['Un', 'Deux', 'Trois'], array $correct = [1], string $type = 'radio', float $points = 1): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Question '.uniqid(),
            'field_type' => $type,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => $options,
            'correct_answer' => $correct,
            'points' => $points,
        ]);
    }

    private function attempt(array $attributes = []): QuizAttempt
    {
        return $this->quiz->attempts()->create(array_merge([
            'reference' => 'ABCDEFGHJK',
        ], $attributes));
    }

    private function start(array $payload = []): TestResponse
    {
        return $this->post(route('quiz.begin', $this->quiz->token), $payload);
    }

    // -------------------------------------------------------------- Références

    public function test_une_reference_inconnue_est_refusee(): void
    {
        $this->question();

        $response = $this->start(['reference' => 'ZZZZZZZZZZ', 'student_name' => 'Jean']);

        $response->assertSessionHasErrors('reference');
        $this->assertSame(0, $this->quiz->attempts()->count());
    }

    public function test_une_reference_mal_formee_est_refusee(): void
    {
        $this->question();

        $response = $this->start(['reference' => 'abc']);

        $response->assertSessionHasErrors('reference');
    }

    public function test_une_reference_valide_ouvre_l_epreuve(): void
    {
        $this->question();
        $attempt = $this->attempt();

        $response = $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean Dupont']);

        $response->assertRedirect(route('quiz.question', $this->quiz->token));

        $attempt->refresh();

        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $attempt->status);
        $this->assertSame('Jean Dupont', $attempt->student_name);
        $this->assertNotNull($attempt->expires_at);
    }

    public function test_la_reference_est_insensible_a_la_casse_et_aux_espaces(): void
    {
        $this->question();
        $attempt = $this->attempt(['reference' => 'ABCDEFGHJK']);

        $this->start(['reference' => 'abcd efgh jk', 'student_name' => 'Jean']);

        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $attempt->refresh()->status);
    }

    public function test_une_reference_deja_utilisee_ne_sert_pas_deux_fois(): void
    {
        $this->question();
        $attempt = $this->attempt([
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
            'started_at' => Carbon::now()->subMinutes(10),
            'score' => 1,
            'max_score' => 1,
        ]);

        $response = $this->start(['reference' => $attempt->reference]);

        $response->assertSessionHasErrors('reference');
    }

    public function test_la_page_d_acces_demande_la_reference_quand_une_liste_est_preparee(): void
    {
        $this->question();
        $this->attempt();

        $this->get(route('quiz.start', $this->quiz->token))
            ->assertOk()
            ->assertSee('Votre référence')
            // L'apostrophe est échappée dans le HTML : on ne vérifie que le verbe.
            ->assertSee('Commencer')
            ->assertSee('30 min');
    }

    public function test_la_page_d_acces_ne_demande_que_le_nom_en_mode_libre(): void
    {
        $this->question();

        $this->get(route('quiz.start', $this->quiz->token))
            ->assertOk()
            ->assertDontSee('Votre référence')
            ->assertSee('Nom complet');
    }

    public function test_la_page_d_acces_signale_une_evaluation_fermee_et_cache_le_bouton(): void
    {
        $this->question();
        $this->quiz->update(['status' => 'inactive']);

        $this->get(route('quiz.start', $this->quiz->token))
            ->assertOk()
            ->assertSee('pas ouverte')
            ->assertDontSee('Commencer');
    }

    public function test_la_page_d_acces_signale_une_evaluation_sans_question(): void
    {
        $this->get(route('quiz.start', $this->quiz->token))
            ->assertOk()
            ->assertSee('aucune question');
    }

    public function test_une_copie_rendue_ramene_au_resultat_et_non_au_formulaire_d_acces(): void
    {
        $question = $this->question();
        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->get(route('quiz.start', $this->quiz->token))
            ->assertRedirect(route('quiz.result', $this->quiz->token));
    }

    public function test_une_epreuve_en_cours_reprend_sans_repasser_par_la_page_d_acces(): void
    {
        $this->question();

        $this->start(['student_name' => 'Jean']);

        $this->get(route('quiz.start', $this->quiz->token))
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        // Le nombre de participations n'a pas bougé : reprendre n'est pas recommencer.
        $this->assertSame(1, $this->quiz->attempts()->count());
    }

    public function test_une_evaluation_fermee_n_accepte_personne(): void
    {
        $this->question();
        $this->quiz->update(['status' => 'inactive']);
        $attempt = $this->attempt();

        $response = $this->start(['reference' => $attempt->reference]);

        $response->assertSessionHas('error');
        $this->assertSame(QuizAttempt::STATUS_PENDING, $attempt->refresh()->status);
    }

    public function test_le_quota_de_participants_est_respecte(): void
    {
        $this->question();
        $this->quiz->update(['max_submissions' => 1]);
        $this->attempt(['status' => QuizAttempt::STATUS_IN_PROGRESS, 'started_at' => Carbon::now()]);
        $second = $this->attempt(['reference' => 'ABCDEFGHJM']);

        $response = $this->start(['reference' => $second->reference]);

        $response->assertSessionHas('error');
        $this->assertSame(QuizAttempt::STATUS_PENDING, $second->refresh()->status);
    }

    // -------------------------------------------------- Mode libre et anonymat

    public function test_en_mode_libre_une_reference_est_attribuee_automatiquement(): void
    {
        $this->question();

        $this->start(['student_name' => 'Jean Dupont', 'student_email' => 'JEAN@Test.com'])
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        $this->assertSame(10, strlen($attempt->reference));
        $this->assertSame('Jean Dupont', $attempt->student_name);
        $this->assertSame('jean@test.com', $attempt->student_email);
    }

    public function test_une_evaluation_anonyme_ne_stocke_aucun_nom(): void
    {
        $quiz = $this->makeQuiz(['is_anonymous' => true], ['duration_minutes' => 5]);
        $this->quiz = $quiz;
        $this->question();

        $this->start(['student_name' => 'Jean Dupont', 'student_email' => 'jean@test.com']);

        $attempt = $quiz->attempts()->firstOrFail();

        $this->assertNull($attempt->student_name);
        $this->assertNull($attempt->student_email);
        $this->assertSame(10, strlen($attempt->reference));
    }

    // ---------------------------------------------------------- Chronomètre

    public function test_le_chrono_est_fixe_au_demarrage_et_ne_bouge_plus(): void
    {
        $this->question();
        $attempt = $this->attempt();

        $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean']);

        $attempt->refresh();
        $expires = $attempt->expires_at->copy();

        $this->assertSame(30, (int) $attempt->started_at->diffInMinutes($expires, false));

        // Le temps passe, l'étudiant recharge : l'échéance reste la même, et
        // aucune nouvelle participation n'est créée.
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->get(route('quiz.question', $this->quiz->token))->assertOk();

        $this->assertSame($expires->timestamp, $attempt->refresh()->expires_at->timestamp);
        $this->assertSame(1, $this->quiz->attempts()->count());
    }

    public function test_une_reponse_apres_l_echeance_est_refusee_et_la_copie_corrigee(): void
    {
        $question = $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $attempt = $this->attempt();

        $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean']);

        // L'étudiant répond correctement à la première question, puis le temps
        // s'écoule avant la seconde.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 1,
        ]);

        $second = $this->question(['A', 'B'], [0], 'radio', 3);

        $attempt->refresh()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $second->id,
            'choice' => 0,
        ])->assertRedirect(route('quiz.result', $this->quiz->token));

        $attempt->refresh();

        $this->assertSame(QuizAttempt::STATUS_EXPIRED, $attempt->status);
        // Seule la réponse enregistrée avant l'échéance compte.
        $this->assertSame('2.00', $attempt->score);
        $this->assertSame('5.00', $attempt->max_score);
        $this->assertSame(0, $attempt->answers()->where('form_field_id', $second->id)->count());
    }

    // ------------------------------------------------- Navigation linéaire

    public function test_on_ne_peut_pas_repondre_a_une_question_sans_avoir_traite_la_precedente(): void
    {
        $first = $this->question();
        $second = $this->question();

        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $second->id,
            'choice' => 0,
        ])->assertRedirect(route('quiz.question', $this->quiz->token));

        $this->assertSame(0, $this->quiz->attempts()->first()->answers()->count());

        // La première question reste bien celle qui est proposée.
        $this->get(route('quiz.question', $this->quiz->token))
            ->assertSee($first->field_label);
    }

    public function test_une_question_validee_ne_peut_plus_etre_modifiee(): void
    {
        $question = $this->question();
        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 0]);

        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1])
            ->assertSessionHas('error');

        $answer = $this->quiz->attempts()->first()->answers()->firstOrFail();

        $this->assertSame([0], $answer->chosenIndexes());
    }

    public function test_apres_la_derniere_question_l_etudiant_est_renvoye_vers_la_confirmation(): void
    {
        $question = $this->question();
        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 0]);

        $this->get(route('quiz.question', $this->quiz->token))
            ->assertRedirect(route('quiz.submit.page', $this->quiz->token));
    }

    // ---------------------------------------------- Correction et résultat

    public function test_la_page_de_confirmation_recapitule_les_reponses_avant_le_rendu(): void
    {
        $question = $this->question();
        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 0]);

        $this->get(route('quiz.submit.page', $this->quiz->token))
            ->assertOk()
            ->assertSee('Rendre ma copie')
            ->assertSee('1 réponse(s) sur 1');
    }

    public function test_la_note_respecte_le_bareme(): void
    {
        $this->question(['Un', 'Deux'], [1], 'radio', 2.5);
        $wrong = $this->question(['Un', 'Deux'], [0], 'radio', 1.5);

        $this->start(['student_name' => 'Jean']);

        $first = $this->quiz->quizQuestions()->first();
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $first->id, 'choice' => 1]);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $wrong->id, 'choice' => 1]);

        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        $this->assertSame(QuizAttempt::STATUS_SUBMITTED, $attempt->status);
        $this->assertSame('2.50', $attempt->score);
        $this->assertSame('4.00', $attempt->max_score);
    }

    public function test_une_question_a_choix_multiple_exige_l_ensemble_exact(): void
    {
        $multiple = $this->question(['A', 'B', 'C', 'D'], [0, 2], 'checkbox', 2);

        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $multiple->id,
            'choice' => [0, 1],
        ]);

        $this->post(route('quiz.submit', $this->quiz->token));

        $this->assertSame('0.00', $this->quiz->attempts()->firstOrFail()->score);
    }

    public function test_l_etudiant_voit_sa_note_et_son_recapitulatif(): void
    {
        $this->question(['Un', 'Deux'], [1], 'radio', 2);

        $this->start(['student_name' => 'Jean Dupont']);

        $question = $this->quiz->quizQuestions()->first();
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('2')
            ->assertSee($attempt->reference)
            ->assertSee('Détail de la correction');
    }

    public function test_la_note_peut_etre_masquee_a_l_etudiant(): void
    {
        $this->quiz = $this->makeQuiz([], ['show_score' => false]);
        $this->question(['Un', 'Deux'], [1]);

        $this->start(['student_name' => 'Jean']);
        $question = $this->quiz->quizQuestions()->first();
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('Votre copie a bien été enregistrée')
            ->assertDontSee('Détail de la correction');
    }

    // ------------------------------------------------------- Récapitulatif PDF

    public function test_le_recapitulatif_pdf_s_obtient_avec_la_reference(): void
    {
        $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $this->start(['student_name' => 'Jean']);
        $question = $this->quiz->quizQuestions()->first();
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        $response = $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_le_recapitulatif_est_refuse_avec_une_reference_inconnue(): void
    {
        $this->get(route('quiz.recap.pdf', [$this->quiz->token, 'ZZZZZZZZZZ']))->assertNotFound();
        $this->get(route('quiz.recap.pdf', [$this->quiz->token, 'pas-une-ref']))->assertNotFound();
    }

    public function test_le_recapitulatif_est_refuse_avant_la_fin_de_l_epreuve(): void
    {
        $this->question();
        $attempt = $this->attempt(['status' => QuizAttempt::STATUS_IN_PROGRESS, 'started_at' => Carbon::now()]);

        $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]))->assertNotFound();
    }

    // ----------------------------------------------- Fuite des bonnes réponses

    public function test_la_page_de_question_ne_livre_aucune_information_de_correction(): void
    {
        $this->question(['Bonne réponse', 'Mauvaise réponse'], [0]);
        $this->start(['student_name' => 'Jean']);

        $response = $this->get(route('quiz.question', $this->quiz->token));

        $response->assertOk();

        // Les propositions sont affichées — mais rien n'indique laquelle est
        // juste : ni la colonne, ni le verdict, ni une case pré-cochée.
        $response->assertSee('Bonne réponse');
        $response->assertDontSee('correct_answer', false);
        $response->assertDontSee('is_correct', false);
        $response->assertDontSee('points_awarded', false);
        $this->assertStringNotContainsString('checked', $response->getContent());
    }

    // ------------------------------------------------------------ Surveillance

    public function test_les_sorties_de_fenetre_sont_journalisees(): void
    {
        $this->question();
        $attempt = $this->attempt();
        $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean']);

        $this->postJson(route('quiz.infraction', $this->quiz->token), ['type' => 'tab_hidden'])
            ->assertOk()
            ->assertJson(['recorded' => true, 'count' => 1]);

        $this->postJson(route('quiz.infraction', $this->quiz->token), ['type' => 'window_blur']);

        $attempt->refresh();

        $this->assertSame(2, $attempt->infraction_count);
        $this->assertCount(2, $attempt->infractions);
        $this->assertSame('tab_hidden', $attempt->infractions[0]['type']);
        $this->assertNotNull($attempt->infractions[0]['at']);
    }

    public function test_un_type_d_infraction_inconnu_est_refuse(): void
    {
        $this->question();
        $attempt = $this->attempt();
        $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean']);

        $this->postJson(route('quiz.infraction', $this->quiz->token), ['type' => 'n_importe_quoi'])
            ->assertStatus(422);

        $this->assertSame(0, $attempt->refresh()->infraction_count);
    }

    // ------------------------------------------------------------ Robustesse

    public function test_un_formulaire_de_depot_n_est_pas_une_evaluation(): void
    {
        $deposit = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'JETON-DEPOT',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->get(route('quiz.start', $deposit->token))->assertNotFound();
        $this->get(route('quiz.question', $deposit->token))->assertNotFound();
    }

    public function test_sans_session_l_etudiant_est_renvoye_vers_l_acces(): void
    {
        $this->question();

        $this->get(route('quiz.question', $this->quiz->token))
            ->assertRedirect(route('quiz.start', $this->quiz->token));
    }

    public function test_une_reponse_invalide_est_refusee(): void
    {
        $question = $this->question(['Un', 'Deux']);
        $this->start(['student_name' => 'Jean']);

        // Index hors des propositions proposées.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 7,
        ])->assertSessionHasErrors('choice');

        $this->assertSame(0, $this->quiz->attempts()->first()->answers()->count());
    }
}

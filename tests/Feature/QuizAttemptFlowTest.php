<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
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

    public function test_un_second_candidat_sur_le_meme_navigateur_n_entre_pas_dans_la_copie_du_premier(): void
    {
        $this->question();

        $premier = $this->attempt(['reference' => 'ABCDEFGHJK']);
        $second = $this->attempt(['reference' => 'ABCDEFGHJM']);

        $this->start(['reference' => $premier->reference, 'student_name' => 'Jean']);

        // Salle informatique : un deuxième étudiant utilise le même poste. Sa
        // référence doit le mener à sa propre copie, pas à celle du précédent.
        $this->start(['reference' => $second->reference, 'student_name' => 'Awa'])
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        $this->assertSame('Awa', $second->refresh()->student_name);
        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $second->status);
        $this->assertSame('Jean', $premier->refresh()->student_name);
        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $premier->status);
    }

    public function test_une_copie_rendue_laisse_le_formulaire_au_candidat_suivant(): void
    {
        $question = $this->question();
        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        // Le poste doit pouvoir servir au candidat suivant (salle informatique) :
        // la copie rendue est rappelée par un lien, le formulaire reste affiché.
        $this->get(route('quiz.start', $this->quiz->token))
            ->assertOk()
            ->assertSee('Une copie a déjà été rendue sur cet appareil')
            ->assertSee('Commencer')
            ->assertSee(route('quiz.result', $this->quiz->token));
    }

    public function test_en_mode_libre_un_second_candidat_demarre_sur_le_meme_navigateur(): void
    {
        $question = $this->question();

        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $premier = $this->quiz->attempts()->firstOrFail();

        // C'est la fin de l'épreuve du premier qui libère le poste, sans qu'il
        // faille fermer le navigateur : l'étudiant suivant saisit son nom et
        // obtient sa propre copie, avec sa propre référence.
        $this->start(['student_name' => 'Awa'])
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        $this->assertSame(2, $this->quiz->attempts()->count());

        $second = $this->quiz->attempts()->orderByDesc('id')->firstOrFail();

        $this->assertNotSame($premier->id, $second->id);
        $this->assertSame('Awa', $second->student_name);
        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $second->status);
        $this->assertNotSame($premier->reference, $second->reference);

        // La copie du premier n'a pas bougé d'un pouce.
        $this->assertSame(QuizAttempt::STATUS_SUBMITTED, $premier->refresh()->status);
        $this->assertSame(1, $premier->answers()->count());
    }

    public function test_en_mode_liste_un_second_candidat_demarre_avec_sa_reference(): void
    {
        $question = $this->question();

        $premier = $this->attempt(['reference' => 'ABCDEFGHJK']);
        $second = $this->attempt(['reference' => 'ABCDEFGHJM']);

        $this->start(['reference' => 'ABCD EFGH JK', 'student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->assertSame(QuizAttempt::STATUS_SUBMITTED, $premier->refresh()->status);

        $this->get(route('quiz.start', $this->quiz->token))->assertOk();

        $this->start(['reference' => $second->reference, 'student_name' => 'Awa'])
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $second->refresh()->status);
        $this->assertSame('Awa', $second->student_name);
    }

    public function test_le_candidat_qui_ressaisit_sa_reference_retrouve_son_resultat(): void
    {
        $question = $this->question();
        $premier = $this->attempt(['reference' => 'ABCDEFGHJK']);

        $this->start(['reference' => $premier->reference, 'student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        // Il revient sur la page d'accès et ressaisit ce qu'il a déjà utilisé :
        // c'est la même personne, on lui montre sa copie — et surtout on n'en
        // ouvre pas une seconde.
        $this->start(['reference' => $premier->reference])
            ->assertRedirect(route('quiz.result', $this->quiz->token));

        $this->assertSame(1, $this->quiz->attempts()->count());
        $this->assertSame(QuizAttempt::STATUS_SUBMITTED, $premier->refresh()->status);
    }

    public function test_une_reference_deja_servie_reste_refusee_sur_un_poste_libre(): void
    {
        $question = $this->question();

        $premier = $this->attempt(['reference' => 'ABCDEFGHJK']);
        $autre = $this->attempt([
            'reference' => 'ABCDEFGHJM',
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
        ]);

        $this->start(['reference' => $premier->reference]);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        // La libération du poste n'ouvre pas une porte dérobée : la référence
        // d'un autre candidat, déjà servie, reste refusée.
        $this->start(['reference' => $autre->reference])->assertSessionHasErrors('reference');

        $this->assertSame(2, $this->quiz->attempts()->count());
    }

    public function test_le_poste_se_detache_apres_une_copie_rendue(): void
    {
        $question = $this->question();
        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->post(route('quiz.new-candidate', $this->quiz->token))
            ->assertRedirect(route('quiz.start', $this->quiz->token));

        // Le poste est vierge : plus de bandeau, plus de copie rattachée.
        $this->get(route('quiz.start', $this->quiz->token))
            ->assertOk()
            ->assertDontSee('Une copie a déjà été rendue sur cet appareil');
    }

    public function test_une_epreuve_en_cours_ne_se_detache_pas(): void
    {
        $premiere = $this->question();
        $this->question();

        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $premiere->id, 'choice' => 1]);

        // Détacher une épreuve en cours ferait perdre à son auteur le fil de sa
        // copie : le candidat qui se trompe de poste est arrêté ici.
        $this->post(route('quiz.new-candidate', $this->quiz->token))
            ->assertRedirect(route('quiz.question', $this->quiz->token))
            ->assertSessionHas('error');

        // La copie est intacte : la deuxième question attend toujours son auteur.
        $this->get(route('quiz.question', $this->quiz->token))->assertOk();
        $this->assertSame(1, $this->quiz->attempts()->count());
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
        // Les deux questions existent avant le démarrage : depuis le tirage figé,
        // une question créée en cours d'épreuve ne rejoint pas la copie en cours.
        $question = $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $second = $this->question(['A', 'B'], [0], 'radio', 3);
        $attempt = $this->attempt();

        $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean']);

        // L'étudiant répond correctement à la première question, puis le temps
        // s'écoule avant la seconde.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 1,
        ]);

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

    // --------------------------------------------------- Questions ouvertes

    private function openQuestion(
        string $label = 'Expliquez la saponification en deux ou trois lignes.',
        float $points = 3,
        ?string $expected = null,
    ): FormField {
        return $this->quiz->fields()->create([
            'field_label' => $label,
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => $expected,
            'points' => $points,
        ]);
    }

    public function test_une_question_ouverte_affiche_une_zone_de_texte_sans_le_guide(): void
    {
        $this->openQuestion(expected: 'Huile + soude, savon et glycérine.');

        $this->start(['student_name' => 'Jean']);

        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertSee('Réponse rédigée')
            ->assertSee('name="answer_text"', false)
            // Le guide de correction est un document de travail : il ne doit
            // jamais traverser jusqu'au navigateur de l'étudiant.
            ->assertDontSee('Huile + soude, savon et glycérine.');
    }

    public function test_une_reponse_redigee_est_enregistree_telle_quelle(): void
    {
        $open = $this->openQuestion();

        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => "  La saponification transforme l'huile et la soude en savon.  ",
        ])->assertRedirect(route('quiz.question', $this->quiz->token));

        $answer = $this->quiz->attempts()->firstOrFail()->answers()->firstOrFail();

        // Les espaces de bord sont retirés, le texte est conservé mot pour mot.
        $this->assertSame("La saponification transforme l'huile et la soude en savon.", $answer->answer_text);
        $this->assertNull($answer->choice);
        // Tant que la correction n'a pas eu lieu, la réponse n'est ni juste ni
        // fausse : elle est en attente.
        $this->assertNull($answer->points_awarded);
        $this->assertNull($answer->is_correct);
    }

    public function test_une_reponse_redigee_vide_ou_faite_d_espaces_est_refusee(): void
    {
        $open = $this->openQuestion();

        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => '',
        ])->assertSessionHasErrors('answer_text');

        // «   » passe la règle `required` de Laravel : c'est le contrôle du
        // contenu qui l'arrête, sans quoi une question ouverte serait « répondue »
        // sans un mot.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => '     ',
        ])->assertSessionHasErrors('answer_text');

        $this->assertSame(0, $this->quiz->attempts()->firstOrFail()->answers()->count());
    }

    public function test_les_reponses_redigees_ne_sont_pas_notees_automatiquement(): void
    {
        $choice = $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $open = $this->openQuestion('Expliquez la saponification.', 3);

        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $choice->id, 'choice' => 1]);
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => 'Huile et soude donnent du savon.',
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        // La note est celle des QCM ; le barème, lui, compte la rédaction.
        $this->assertSame('2.00', $attempt->score);
        $this->assertSame('5.00', $attempt->max_score);
        $this->assertSame(1, $attempt->pendingManualCount());
        $this->assertTrue($attempt->awaitsManualGrading());

        // L'étudiant doit lire que sa note est provisoire.
        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('Note provisoire')
            ->assertSee('1 réponse(s) rédigée(s) en attente de correction')
            ->assertSee('Huile et soude donnent du savon.')
            ->assertSee('En attente de correction');
    }

    public function test_un_encodage_invalide_ne_perd_pas_la_copie(): void
    {
        $open = $this->openQuestion();

        $this->start(['student_name' => 'Jean']);

        // « glycé » écrit en latin-1 : suite d'octets invalide en UTF-8. MySQL en
        // utf8mb4 strict refuserait l'insertion, et l'étudiant perdrait sa copie
        // sur une erreur 500. Les octets fautifs sont neutralisés.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => "glyc\xE9rine",
        ])->assertRedirect(route('quiz.question', $this->quiz->token));

        $text = (string) $this->quiz->attempts()->firstOrFail()->answers()->firstOrFail()->answer_text;

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString('glyc', $text);
    }

    public function test_la_page_de_confirmation_annonce_les_questions_redigees(): void
    {
        $choice = $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $open = $this->openQuestion('Expliquez la saponification.', 3);

        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $choice->id, 'choice' => 1]);
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => 'Huile et soude donnent du savon.',
        ]);

        $this->get(route('quiz.submit.page', $this->quiz->token))
            ->assertOk()
            ->assertSee('seront corrigées par votre enseignant')
            ->assertSee('note sera provisoire');
    }

    public function test_une_question_ouverte_sans_reponse_ne_laisse_pas_la_copie_en_attente(): void
    {
        $choice = $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $this->openQuestion('Expliquez la saponification.', 3);

        $this->start(['student_name' => 'Jean']);

        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $choice->id, 'choice' => 1]);

        // Le temps s'écoule avant la question rédigée : il n'y a rien à corriger,
        // la question vaut zéro par absence, comme un QCM non répondu.
        $this->quiz->attempts()->firstOrFail()->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        $this->assertSame(QuizAttempt::STATUS_EXPIRED, $attempt->status);
        $this->assertSame('2.00', $attempt->score);
        $this->assertSame(0, $attempt->pendingManualCount());
        $this->assertFalse($attempt->awaitsManualGrading());
    }

    public function test_le_recapitulatif_pdf_porte_la_mention_provisoire(): void
    {
        $choice = $this->question(['Un', 'Deux'], [1], 'radio', 2);
        $open = $this->openQuestion('Expliquez la saponification.', 3);

        $this->start(['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $choice->id, 'choice' => 1]);
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => 'Huile et soude donnent du savon.',
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail()->load('answers');

        $response = $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        // Le rendu du document, lui, se vérifie : DomPDF ne produit pas de texte
        // lisible dans le binaire, mais la vue est la source du PDF.
        $followLink = ShortLink::forAttempt($this->quiz, $attempt);

        $pending = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'pending' => 1,
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
        ])->render();

        $this->assertStringContainsString('Note provisoire', $pending);
        $this->assertStringContainsString('En attente de correction', $pending);
        $this->assertStringContainsString('Huile et soude donnent du savon.', $pending);

        $definitive = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'pending' => 0,
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
        ])->render();

        $this->assertStringContainsString('Note obtenue', $definitive);
        $this->assertStringNotContainsString('Note provisoire', $definitive);
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

    public function test_deux_signaux_pour_une_meme_sortie_ne_comptent_qu_une_fois(): void
    {
        $this->question();
        $attempt = $this->attempt();
        $this->start(['reference' => $attempt->reference, 'student_name' => 'Jean']);

        $this->postJson(route('quiz.infraction', $this->quiz->token), ['type' => 'fullscreen_exit'])
            ->assertOk()
            ->assertJson(['recorded' => true, 'count' => 1]);

        // Le navigateur peut signaler la même sortie deux fois : le second
        // signal est acquitté sans être écrit, et le compteur ne bouge pas.
        $this->postJson(route('quiz.infraction', $this->quiz->token), ['type' => 'fullscreen_exit'])
            ->assertOk()
            ->assertJson(['recorded' => false, 'count' => 1]);

        $attempt->refresh();

        $this->assertSame(1, $attempt->infraction_count);
        $this->assertCount(1, $attempt->infractions);
        $this->assertSame('1 plein écran quitté', $attempt->infractionSummary());

        // Deux secondes plus tard, une nouvelle sortie est une vraie sortie.
        Carbon::setTestNow(Carbon::now()->addSeconds(3));

        $this->postJson(route('quiz.infraction', $this->quiz->token), ['type' => 'fullscreen_exit'])
            ->assertOk()
            ->assertJson(['recorded' => true, 'count' => 2]);

        // Le message lu par le candidat nomme ce qui s'est passé.
        $this->assertSame(
            'Sortie du plein écran enregistrée.',
            QuizAttempt::infractionMessage('fullscreen_exit')
        );
    }

    // --------------------------------------------------------- Plein écran

    public function test_le_choix_du_plein_ecran_est_memorise_pour_l_epreuve(): void
    {
        $this->question();
        $this->start(['student_name' => 'Jean', 'fullscreen' => '1']);

        // Le choix vit en session, pas dans le navigateur : il survit donc à un
        // rechargement de la page de question.
        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertSee('data-fullscreen-preferred="1"', false)
            // Le libellé lui-même vient du serveur, pas d'un script.
            ->assertSee('>Passer en plein écran (recommandé)</span>', false);
    }

    public function test_sans_le_choix_le_bouton_n_est_pas_recommande(): void
    {
        $this->question();
        $this->start(['student_name' => 'Jean']);

        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertSee('data-fullscreen-preferred="0"', false)
            ->assertSee('>Passer en plein écran</span>', false);
    }

    public function test_le_plein_ecran_ne_se_demande_plus_au_premier_clic(): void
    {
        $this->question();
        $this->start(['student_name' => 'Jean']);

        // L'ancien comportement demandait le plein écran sur le premier clic de
        // la page, quel qu'il soit — souvent le clic de réponse lui-même.
        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertDontSee("document.addEventListener('click', function once()", false)
            ->assertSee('quiz-fullscreen-toggle');

        // Et la sortie de page n'est plus bloquée pendant toute l'épreuve : le
        // garde-fou ne vise plus qu'un texte rédigé non validé.
        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertDontSee('if (left > 0) {', false);
    }

    // ------------------------------------------ Réponse sans rechargement

    public function test_une_reponse_peut_etre_envoyee_sans_recharger_la_page(): void
    {
        $first = $this->question(['Un', 'Deux'], [0]);
        $second = $this->question(['Trois', 'Quatre'], [0]);
        $this->start(['student_name' => 'Jean']);

        $response = $this->postJson(route('quiz.answer', $this->quiz->token), [
            'question_id' => $first->id,
            'choice' => 0,
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'position' => 2, 'total' => 2]);

        // Le fragment renvoyé est la carte de la question suivante, identique à
        // celle qu'afficherait la page complète.
        $this->assertStringContainsString('Question 2 sur 2', $response->json('html'));
        $this->assertStringContainsString($second->field_label, $response->json('html'));

        // Et il ne livre toujours aucune information de correction.
        $this->assertStringNotContainsString('correct_answer', $response->json('html'));
        $this->assertStringNotContainsString('points_awarded', $response->json('html'));

        $this->assertNotNull($response->json('remaining'));
        $this->assertSame(1, $this->quiz->attempts()->firstOrFail()->answers()->count());
    }

    public function test_la_derniere_reponse_mene_a_la_page_de_confirmation(): void
    {
        $only = $this->question(['Un', 'Deux'], [0]);
        $this->start(['student_name' => 'Jean']);

        $response = $this->postJson(route('quiz.answer', $this->quiz->token), [
            'question_id' => $only->id,
            'choice' => 0,
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'navigate' => route('quiz.submit.page', $this->quiz->token),
        ]);

        $this->assertNull($response->json('html'));
    }

    public function test_une_question_deja_validee_rafraichit_la_carte(): void
    {
        $first = $this->question(['Un', 'Deux'], [0]);
        $second = $this->question(['Trois', 'Quatre'], [0]);
        $this->start(['student_name' => 'Jean']);

        $payload = ['question_id' => $first->id, 'choice' => 0];

        $this->postJson(route('quiz.answer', $this->quiz->token), $payload)->assertOk();

        $response = $this->postJson(route('quiz.answer', $this->quiz->token), $payload);

        $response->assertOk()->assertJson([
            'ok' => true,
            'replace' => true,
            'error' => 'Cette question a déjà été validée.',
        ]);

        // La carte renvoyée est celle de la question encore à traiter, sans quoi
        // le candidat resterait devant une question qui ne reviendra jamais.
        $this->assertStringContainsString($second->field_label, $response->json('html'));
        $this->assertSame(1, $this->quiz->attempts()->firstOrFail()->answers()->count());
    }

    public function test_une_reponse_vide_est_refusee_avec_les_erreurs_en_json(): void
    {
        $question = $this->question(['Un', 'Deux'], [0]);
        $this->start(['student_name' => 'Jean']);

        // C'est ce que la page lit pour afficher l'erreur sans rechargement :
        // la clé et la phrase doivent rester celles du rendu classique.
        $this->postJson(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['choice' => 'Sélectionnez une réponse avant de continuer.']);

        $this->assertSame(0, $this->quiz->attempts()->firstOrFail()->answers()->count());
    }

    public function test_sans_javascript_la_reponse_redirige_comme_avant(): void
    {
        $question = $this->question(['Un', 'Deux'], [0]);
        $this->start(['student_name' => 'Jean']);

        // Chemin classique, sans en-tête AJAX : rien ne change pour lui.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 0,
        ])->assertRedirect(route('quiz.question', $this->quiz->token));
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

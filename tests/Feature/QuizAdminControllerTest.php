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
 * Administration des évaluations : création, questions, références,
 * résultats et réinitialisation d'une participation.
 *
 * Ce fichier vérifie aussi le garde-fou structurel du module : aucune route
 * d'évaluation ne doit accepter un dépôt de travaux, et inversement.
 */
class QuizAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    private int $counter = 0;

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

        $this->quiz = $this->makeQuiz();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function makeQuiz(array $attributes = []): Form
    {
        return Form::create(array_merge([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-'.uniqid(),
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => true],
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function makeDeposit(): Form
    {
        return Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'DEPOT-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);
    }

    private function question(array $attributes = []): FormField
    {
        return $this->quiz->fields()->create(array_merge([
            'field_label' => 'Question '.uniqid(),
            'field_type' => 'radio',
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => ['Un', 'Deux', 'Trois'],
            'correct_answer' => [1],
            'points' => 1,
        ], $attributes));
    }

    private function attempt(array $attributes = []): QuizAttempt
    {
        return $this->quiz->attempts()->create(array_merge([
            'reference' => $this->reference(),
        ], $attributes));
    }

    /** Une référence valide : dix caractères, sans les lettres ambiguës. */
    private function reference(): string
    {
        return 'REF'.str_pad((string) ++$this->counter, 7, '2', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, int>  $correct
     */
    private function questionPayload(array $options = ['Un', 'Deux', 'Trois', ''], array $correct = [1], float $points = 1, string $type = 'radio'): array
    {
        return [
            'field_label' => 'Quelle est la bonne réponse ?',
            'field_type' => $type,
            'options' => $options,
            'correct' => $correct,
            'points' => $points,
        ];
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Examen Algorithmique',
            'duration_minutes' => 30,
            'is_anonymous' => '1',
            'show_score' => '1',
            'proctoring' => '1',
        ], $overrides);
    }

    // ------------------------------------------------------------------ Liste

    public function test_la_liste_ne_montre_que_les_evaluations(): void
    {
        $deposit = $this->makeDeposit();

        $this->get(route('admin.quizzes.index'))
            ->assertOk()
            ->assertSee($this->quiz->title)
            ->assertDontSee($deposit->title);
    }

    public function test_la_liste_compte_les_questions_et_les_participations(): void
    {
        $this->question();
        $this->question(['field_type' => 'checkbox', 'correct_answer' => [0]]);
        $this->attempt(['status' => QuizAttempt::STATUS_IN_PROGRESS, 'started_at' => Carbon::now()]);
        $this->attempt(['status' => QuizAttempt::STATUS_SUBMITTED, 'submitted_at' => Carbon::now()]);

        $quiz = Form::where('type', Form::TYPE_QUIZ)
            ->withCount([
                'fields as questions_count' => fn ($query) => $query->whereIn('field_type', FormField::QUESTION_TYPES),
                'attempts',
            ])
            ->firstOrFail();

        $this->assertSame(2, $quiz->questions_count);
        $this->assertSame(2, $quiz->attempts_count);

        $this->get(route('admin.quizzes.index'))->assertOk()->assertSee('Rendues');
    }

    public function test_la_liste_est_vide_sans_evaluation(): void
    {
        $this->quiz->delete();

        $this->get(route('admin.quizzes.index'))
            ->assertOk()
            ->assertSee('Aucune évaluation');
    }

    public function test_les_pages_de_configuration_et_de_resultats_s_affichent(): void
    {
        $this->question();
        $this->attempt([
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(5),
            'submitted_at' => Carbon::now(),
            'score' => 1,
            'max_score' => 3,
        ]);

        $this->get(route('admin.quizzes.create'))->assertOk()->assertSee('Nouvelle évaluation');
        $this->get(route('admin.quizzes.show', $this->quiz))->assertOk()->assertSee($this->quiz->title);
        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('Résultats')
            ->assertSee('Exporter en CSV');
    }

    // -------------------------------------------------------------- Réglages

    public function test_une_evaluation_est_creee_fermee_avec_ses_reglages(): void
    {
        $response = $this->post(route('admin.quizzes.store'), [
            'title' => 'Examen Réseaux',
            'description' => 'Deux heures, documents interdits.',
            'duration_minutes' => 45,
            'max_submissions' => 60,
            'is_anonymous' => '1',
            'show_score' => '1',
            'proctoring' => '1',
        ]);

        $quiz = Form::where('title', 'Examen Réseaux')->firstOrFail();

        $response->assertRedirect(route('admin.quizzes.show', $quiz));

        $this->assertTrue($quiz->isQuiz());
        // Une évaluation naît fermée : on ajoute les questions avant d'ouvrir.
        $this->assertSame('inactive', $quiz->status);
        $this->assertSame(45, $quiz->quizDurationMinutes());
        $this->assertTrue($quiz->is_anonymous);
        $this->assertTrue($quiz->quizShowsScore());
        $this->assertNull($quiz->quiz_settings['max_submissions'] ?? null);
        $this->assertSame($this->admin->id, $quiz->created_by);
    }

    public function test_la_duree_est_obligatoire_et_bornee(): void
    {
        $this->post(route('admin.quizzes.store'), $this->settingsPayload(['duration_minutes' => 0]))
            ->assertSessionHasErrors('duration_minutes');

        $this->post(route('admin.quizzes.store'), $this->settingsPayload(['duration_minutes' => 601]))
            ->assertSessionHasErrors('duration_minutes');

        $this->post(route('admin.quizzes.store'), $this->settingsPayload(['title' => '']))
            ->assertSessionHasErrors('title');

        $this->assertSame(1, Form::where('type', Form::TYPE_QUIZ)->count());
    }

    public function test_les_reglages_sont_modifiables(): void
    {
        $this->put(route('admin.quizzes.update', $this->quiz), $this->settingsPayload([
            'title' => 'Examen Algorithmique — session 2',
            'duration_minutes' => 90,
            'max_submissions' => 25,
        ]))->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $this->quiz->refresh();

        $this->assertSame('Examen Algorithmique — session 2', $this->quiz->title);
        $this->assertSame(90, $this->quiz->quizDurationMinutes());
        $this->assertSame(25, (int) $this->quiz->max_submissions);
        // Les cases non cochées retombent à false : c'est ce que voit l'admin.
        $this->assertTrue($this->quiz->is_anonymous);
    }

    public function test_une_evaluation_s_ouvre_et_se_ferme(): void
    {
        $this->patch(route('admin.quizzes.toggle', $this->quiz));
        $this->assertSame('active', $this->quiz->refresh()->status);

        $this->patch(route('admin.quizzes.toggle', $this->quiz));
        $this->assertSame('inactive', $this->quiz->refresh()->status);
    }

    public function test_une_evaluation_peut_etre_supprimee(): void
    {
        $this->delete(route('admin.quizzes.destroy', $this->quiz))
            ->assertRedirect(route('admin.quizzes.index'));

        $this->assertNull($this->quiz->fresh());
        $this->assertSame(0, Form::where('type', Form::TYPE_QUIZ)->count());
    }

    // ------------------------------------------------------------- Questions

    public function test_une_question_est_ajoutee_avec_ses_propositions(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Paris', 'Lyon', 'Marseille', ''],
            [1],
            2.5,
            'radio'
        ))->assertRedirect();

        $question = $this->quiz->quizQuestions()->firstOrFail();

        $this->assertSame(['Paris', 'Lyon', 'Marseille'], $question->getOptionsList());
        $this->assertSame([1], $question->correctIndexes());
        $this->assertSame('2.50', $question->points);
        $this->assertSame(2.5, $this->quiz->quizMaxScore());
    }

    public function test_une_proposition_vide_est_ignoree_et_les_bonnes_reponses_suivent(): void
    {
        // La troisième proposition est vide, la bonne réponse est la quatrième :
        // sans remappage, le barème désignerait une proposition inexistante.
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', '', 'Quatre'],
            [3]
        ))->assertRedirect();

        $question = $this->quiz->quizQuestions()->firstOrFail();

        $this->assertSame(['Un', 'Deux', 'Quatre'], $question->getOptionsList());
        $this->assertSame([2], $question->correctIndexes());
    }

    public function test_une_question_a_choix_unique_refuse_deux_bonnes_reponses(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', 'Trois', ''],
            [0, 1],
            1,
            'radio'
        ))->assertSessionHasErrors('correct');

        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_une_question_a_choix_multiple_accepte_plusieurs_bonnes_reponses(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', 'Trois', ''],
            [0, 2],
            1,
            'checkbox'
        ))->assertRedirect();

        $this->assertSame([0, 2], $this->quiz->quizQuestions()->firstOrFail()->correctIndexes());
    }

    public function test_il_faut_au_moins_deux_propositions_non_vides(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', 'Trois', ''],
            [2]
        ))->assertRedirect();

        // Cette fois une seule proposition est réellement remplie.
        $response = $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', '', '', ''],
            [0]
        ));

        $response->assertSessionHasErrors('options');
        $this->assertSame(1, $this->quiz->quizQuestions()->count());
    }

    public function test_il_faut_designer_une_bonne_reponse(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', 'Trois', ''],
            []
        ))->assertSessionHasErrors('correct');

        // Une bonne réponse pointant sur une proposition vide est refusée aussi.
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', '', ''],
            [3]
        ))->assertSessionHasErrors('correct');

        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_le_bareme_doit_etre_positif(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(
            ['Un', 'Deux', 'Trois', ''],
            [0],
            0
        ))->assertSessionHasErrors('points');
    }

    public function test_une_question_est_modifiable_et_supprimable(): void
    {
        $question = $this->question();

        $this->put(route('admin.quizzes.questions.update', [$this->quiz, $question]), $this->questionPayload(
            ['Vrai', 'Faux', '', ''],
            [0],
            3
        ))->assertRedirect();

        $question->refresh();

        $this->assertSame(['Vrai', 'Faux'], $question->getOptionsList());
        $this->assertSame([0], $question->correctIndexes());
        $this->assertSame('3.00', $question->points);

        $this->delete(route('admin.quizzes.questions.destroy', [$this->quiz, $question]))->assertRedirect();

        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_les_questions_sont_numerotees_dans_l_ordre_d_ajout(): void
    {
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(['Un', 'Deux', '', ''], [0]));
        $this->post(route('admin.quizzes.questions.store', $this->quiz), $this->questionPayload(['Un', 'Deux', '', ''], [1]));

        $orders = $this->quiz->quizQuestions()->pluck('order')->all();

        $this->assertSame([1, 2], array_map('intval', $orders));
    }

    public function test_une_question_d_un_autre_formulaire_est_inaccessible(): void
    {
        $deposit = $this->makeDeposit();
        $foreign = $deposit->fields()->create([
            'field_label' => 'Nom complet',
            'field_type' => 'text',
            'required' => true,
            'order' => 1,
        ]);

        $this->delete(route('admin.quizzes.questions.destroy', [$this->quiz, $foreign]))->assertNotFound();
        $this->put(route('admin.quizzes.questions.update', [$this->quiz, $foreign]), $this->questionPayload())
            ->assertNotFound();

        $this->assertNotNull($foreign->fresh());
    }

    // ------------------------------------------------------------ Références

    public function test_des_references_sont_generees_pour_la_liste_des_etudiants(): void
    {
        $this->post(route('admin.quizzes.references.store', $this->quiz), ['count' => 12])
            ->assertRedirect();

        $references = $this->quiz->attempts()->pluck('reference')->all();

        $this->assertCount(12, $references);
        $this->assertSame($references, array_unique($references));

        foreach ($references as $reference) {
            $this->assertSame(10, strlen($reference));
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10}$/', $reference);
        }

        // Une référence préparée attend son étudiant.
        $this->assertSame(0, $this->quiz->attempts()->where('status', '!=', QuizAttempt::STATUS_PENDING)->count());
        $this->assertTrue($this->quiz->quizHasPreparedReferences());
    }

    public function test_le_nombre_de_references_est_borne(): void
    {
        $this->post(route('admin.quizzes.references.store', $this->quiz), ['count' => 0])
            ->assertSessionHasErrors('count');

        $this->post(route('admin.quizzes.references.store', $this->quiz), ['count' => 501])
            ->assertSessionHasErrors('count');

        $this->assertSame(0, $this->quiz->attempts()->count());
    }

    public function test_les_references_ne_se_marchent_pas_dessus_entre_deux_evaluations(): void
    {
        $autre = $this->makeQuiz(['title' => 'Examen Physique']);

        $this->post(route('admin.quizzes.references.store', $this->quiz), ['count' => 30]);
        $this->post(route('admin.quizzes.references.store', $autre), ['count' => 30]);

        $references = $this->quiz->attempts()->pluck('reference')
            ->merge($autre->attempts()->pluck('reference'))
            ->all();

        $this->assertCount(60, $references);
        $this->assertCount(60, array_unique($references));
    }

    // -------------------------------------------------------------- Résultats

    public function test_la_reinitialisation_efface_les_reponses_et_rend_la_place(): void
    {
        $question = $this->question();
        $attempt = $this->attempt([
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 3,
            'max_score' => 3,
            'infractions' => [['type' => 'tab_hidden', 'detail' => null, 'at' => '2026-09-20 09:05:00', 'elapsed' => 120]],
            'infraction_count' => 1,
        ]);

        $attempt->answers()->create([
            'form_field_id' => $question->id,
            'choice' => [1],
            'is_correct' => true,
            'points_awarded' => 3,
            'answered_at' => Carbon::now(),
        ]);

        $this->post(route('admin.quizzes.attempts.reset', [$this->quiz, $attempt]))->assertRedirect();

        $attempt->refresh();

        $this->assertSame(QuizAttempt::STATUS_PENDING, $attempt->status);
        $this->assertNull($attempt->started_at);
        $this->assertNull($attempt->expires_at);
        $this->assertNull($attempt->submitted_at);
        $this->assertNull($attempt->score);
        $this->assertSame(0, $attempt->infraction_count);
        $this->assertSame(0, $attempt->answers()->count());
        // La référence reste la même : c'est elle que l'étudiant a reçue.
        $this->assertSame(10, strlen($attempt->reference));
    }

    public function test_la_reinitialisation_refuse_une_participation_d_un_autre_formulaire(): void
    {
        $autre = $this->makeQuiz(['title' => 'Examen Physique']);
        $attempt = $autre->attempts()->create(['reference' => $this->reference()]);

        $this->post(route('admin.quizzes.attempts.reset', [$this->quiz, $attempt]))->assertNotFound();
        $this->assertSame(QuizAttempt::STATUS_PENDING, $attempt->refresh()->status);
    }

    public function test_l_export_csv_contient_l_en_tete_et_les_participations(): void
    {
        $question = $this->question(['points' => 2]);
        $attempt = $this->attempt([
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'student_name' => 'Curie Marie',
            'student_email' => 'marie@test.com',
            'student_major' => 'MPI',
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 2,
            'max_score' => 2,
            'infraction_count' => 2,
        ]);

        $attempt->answers()->create([
            'form_field_id' => $question->id,
            'choice' => [1],
            'is_correct' => true,
            'points_awarded' => 2,
        ]);

        $response = $this->get(route('admin.quizzes.results.export', $this->quiz));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Référence;Nom;Email;Filière;Statut;Note;Barème;"Temps (min)";Infractions;"Commencée le";"Terminée le"', $csv);
        $this->assertStringContainsString($attempt->reference, $csv);
        $this->assertStringContainsString('Curie Marie', $csv);
        $this->assertStringContainsString('Terminée', $csv);
        // La ligne complète : note, barème, temps passé et infractions.
        $this->assertStringContainsString(';MPI;Terminée;2,00;2,00;10;2;', $csv);
    }

    public function test_l_export_csv_masque_les_noms_quand_l_evaluation_est_anonyme(): void
    {
        $this->quiz->update(['is_anonymous' => true]);

        $this->attempt([
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'student_name' => 'Curie Marie',
            'submitted_at' => Carbon::now(),
            'score' => 1,
            'max_score' => 1,
        ]);

        $csv = $this->get(route('admin.quizzes.results.export', $this->quiz))->streamedContent();

        $this->assertStringNotContainsString('Curie Marie', $csv);
    }

    public function test_un_nom_d_etudiant_ne_s_execute_pas_dans_le_tableur(): void
    {
        $this->attempt([
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'student_name' => '=1+1',
            'submitted_at' => Carbon::now(),
            'score' => 0,
            'max_score' => 1,
        ]);

        $csv = $this->get(route('admin.quizzes.results.export', $this->quiz))->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
    }

    // ------------------------------------------------------------ Robustesse

    public function test_aucune_route_d_evaluation_n_est_ouverte_sans_session_admin(): void
    {
        $this->flushSession();

        $this->get(route('admin.quizzes.index'))->assertRedirect(route('login'));
        $this->get(route('admin.quizzes.create'))->assertRedirect(route('login'));
        $this->get(route('admin.quizzes.show', $this->quiz))->assertRedirect(route('login'));
        $this->get(route('admin.quizzes.results', $this->quiz))->assertRedirect(route('login'));
        $this->get(route('admin.quizzes.results.export', $this->quiz))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, TestResponse>
     */
    public function test_les_routes_d_evaluation_refusent_un_depot_de_travaux(): void
    {
        $deposit = $this->makeDeposit();

        $this->get(route('admin.quizzes.show', $deposit))->assertNotFound();
        $this->get(route('admin.quizzes.results', $deposit))->assertNotFound();
        $this->put(route('admin.quizzes.update', $deposit), $this->settingsPayload())->assertNotFound();
        $this->delete(route('admin.quizzes.destroy', $deposit))->assertNotFound();
        $this->post(route('admin.quizzes.questions.store', $deposit), $this->questionPayload())->assertNotFound();
        $this->post(route('admin.quizzes.references.store', $deposit), ['count' => 5])->assertNotFound();

        // Et le dépôt n'a pas bougé : ni question, ni participation.
        $this->assertSame(0, $deposit->fields()->count());
        $this->assertSame(0, $deposit->attempts()->count());
    }
}

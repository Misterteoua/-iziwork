<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Renotation d'une question modifiée.
 *
 * Une bonne réponse se corrige après coup : la note des copies déjà rendues doit
 * suivre, sans quoi l'enseignant lit un score que sa propre correction
 * contredit. Quatre garanties tiennent ce fichier :
 *
 *   1. la note dérivée est recalculée dans les deux sens — fausse devenue juste,
 *      juste devenue fausse — et le total de la copie avec elle ;
 *   2. une note posée par un humain (correcteur, administration) n'est jamais
 *      réécrite : le tri se fait sur l'auteur, pas sur la date ;
 *   3. une copie en cours d'épreuve n'est pas touchée : elle sera corrigée avec
 *      la définition en vigueur à sa remise ;
 *   4. une question que la machine ne peut plus trancher — devenue « réponse
 *      rédigée », ou évaluation passée en correction manuelle des QCM — retombe
 *      « en attente » et rejoint la file de correction ;
 *   5. cet effet est chiffré **avant** l'enregistrement, sur l'écran de
 *      configuration, et cet aperçu n'écrit rien — la question modifiée se juge
 *      avant d'être posée, pas après avoir changé la note d'étudiants.
 */
class QuizRegradeTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));

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

    /**
     * @param  array<string, mixed>  $settings
     */
    private function makeQuiz(array $settings = []): Form
    {
        return Form::create([
            'title' => 'Évaluation renotée',
            'token' => 'JETON-RENOTE-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => array_merge([
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
                'requires_reference' => false,
            ], $settings),
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, int>  $correct
     */
    private function choiceQuestion(
        array $options = ['Vrai', 'Faux'],
        array $correct = [0],
        float $points = 2,
        ?Form $quiz = null,
    ): FormField {
        $quiz ??= $this->quiz;

        return $quiz->fields()->create([
            'field_label' => 'La Terre est ronde.',
            'field_type' => count($correct) > 1 ? 'checkbox' : 'radio',
            'required' => true,
            'order' => (int) $quiz->fields()->max('order') + 1,
            'options' => $options,
            'correct_answer' => $correct,
            'points' => $points,
        ]);
    }

    /**
     * Joue une copie entière par les routes réelles : commencer, répondre,
     * remettre — puis libère le poste pour le candidat suivant.
     *
     * @param  array<int, int|array<int, int>>  $choices  la réponse donnée à chaque question, dans l'ordre
     */
    private function playCopy(array $choices = [], ?Form $quiz = null): QuizAttempt
    {
        $quiz ??= $this->quiz;

        $this->post(route('quiz.begin', $quiz->token), ['student_name' => 'Candidat Test']);

        foreach ($quiz->quizQuestions()->get() as $index => $question) {
            $this->post(route('quiz.answer', $quiz->token), [
                'question_id' => $question->id,
                'choice' => $choices[$index] ?? 0,
            ]);
        }

        $this->post(route('quiz.submit', $quiz->token));

        $attempt = $quiz->attempts()->orderByDesc('id')->firstOrFail();

        // Deux copies dans la même épreuve ne doivent pas se confondre dans la
        // session du navigateur : le poste change de mains.
        $this->post(route('quiz.new-candidate', $quiz->token));

        return $attempt;
    }

    /**
     * Enregistre la question comme le ferait l'écran « Modifier cette question ».
     *
     * Le payload complet part de l'état enregistré, et seules les valeurs testées
     * sont remplacées : un test ne doit pas changer autre chose que ce qu'il
     * annonce.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function saveQuestion(FormField $question, array $overrides = [], ?Form $quiz = null): TestResponse
    {
        $quiz ??= $this->quiz;

        return $this->put(route('admin.quizzes.questions.update', [$quiz, $question]), array_merge([
            'field_label' => $question->field_label,
            'field_type' => $question->field_type,
            'options' => $question->getOptionsList(),
            'correct' => $question->correctIndexes(),
            'points' => (float) $question->points,
        ], $overrides));
    }

    private function answerFor(QuizAttempt $attempt, FormField $question): QuizAnswer
    {
        return $attempt->answers()->where('form_field_id', $question->id)->firstOrFail();
    }

    // ------------------------------------------------- Recorrection des notes

    public function test_corriger_la_bonne_reponse_renote_les_copies_deja_rendues(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $attempt = $this->playCopy([0]);

        $answer = $this->answerFor($attempt, $question);

        // Le candidat a coché la bonne réponse : quatre points, copie entièrement
        // corrigée, et rien en attente.
        $this->assertSame('4.00', $answer->points_awarded);
        $this->assertTrue($answer->is_correct);
        $this->assertSame('4.00', $attempt->refresh()->score);
        $this->assertSame('4.00', $attempt->max_score);

        // L'enseignant s'est trompé : la bonne réponse est « Faux ».
        $this->saveQuestion($question, ['correct' => [1]])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 copie(s) rendue(s) renotée(s)'));

        $answer->refresh();
        $attempt->refresh();

        $this->assertSame('0.00', $answer->points_awarded);
        $this->assertFalse($answer->is_correct);
        $this->assertSame('0.00', $attempt->score);
        // Seule la bonne réponse a changé : le barème de la copie ne bouge pas.
        $this->assertSame('4.00', $attempt->max_score);

        // La correction se rattrape dans l'autre sens aussi : c'est le même
        // chemin, il ne doit pas être à sens unique.
        $this->saveQuestion($question, ['correct' => [0]])->assertRedirect();

        $this->assertSame('4.00', $answer->refresh()->points_awarded);
        $this->assertTrue($answer->is_correct);
        $this->assertSame('4.00', $attempt->refresh()->score);
    }

    public function test_modifier_le_bareme_met_a_jour_la_note_et_le_total_des_copies(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $right = $this->playCopy([0]);
        $wrong = $this->playCopy([1]);

        // Une copie rendue qui n'a rien coché : aucune ligne de réponse ne la
        // représente, et pourtant son total « sur combien » dépend du barème.
        $blank = $this->quiz->attempts()->create([
            'reference' => 'BLANKRENOT',
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 0,
            'max_score' => 2,
        ]);

        $this->saveQuestion($question, ['points' => 5])->assertRedirect();

        $this->assertSame('5.00', $this->answerFor($right, $question)->points_awarded);
        $this->assertSame('5.00', $right->refresh()->score);
        $this->assertSame('5.00', $right->max_score);

        // Une mauvaise réponse reste à zéro, mais le barème de sa copie suit.
        $this->assertSame('0.00', $this->answerFor($wrong, $question)->points_awarded);
        $this->assertSame('0.00', $wrong->refresh()->score);
        $this->assertSame('5.00', $wrong->max_score);

        $this->assertSame('0.00', $blank->refresh()->score);
        $this->assertSame('5.00', $blank->max_score);
    }

    public function test_une_note_posee_par_un_humain_n_est_jamais_recalculee(): void
    {
        $this->quiz = $this->makeQuiz(['manual_choice_grading' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([1]);
        $answer = $this->answerFor($attempt, $question);

        // L'enseignant note lui-même le QCM, contre l'avis de la machine : deux
        // points accordés pour une réponse qu'elle déclarerait fausse.
        $this->post(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
            'comments' => [$answer->id => 'Réponse justifiée à l’oral.'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->admin->id, $answer->refresh()->graded_by_admin_id);

        // Puis la bonne réponse est corrigée : la note de l'enseignant reste.
        $this->saveQuestion($question, ['correct' => [0]])->assertRedirect();

        $answer->refresh();

        $this->assertSame('2.00', $answer->points_awarded);
        $this->assertSame('Réponse justifiée à l’oral.', $answer->grader_comment);
        $this->assertSame($this->admin->id, $answer->graded_by_admin_id);
    }

    // --------------------------------------------- Copies que l'on ne touche pas

    public function test_une_copie_en_cours_recoit_la_nouvelle_definition(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);

        // Le candidat a ouvert son épreuve mais n'a pas encore répondu.
        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Candidat Test']);

        $attempt = $this->quiz->attempts()->orderByDesc('id')->firstOrFail();

        $this->saveQuestion($question, ['correct' => [1]])
            ->assertRedirect()
            // Sa copie n'est pas rendue : il n'y a rien à renoter, et le message
            // ne doit pas le laisser croire.
            ->assertSessionHas('success', 'Question mise à jour.');

        $attempt->refresh();

        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $attempt->status);
        $this->assertNull($attempt->score);

        // Le candidat répond la nouvelle bonne réponse, puis remet.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 1,
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->assertSame('2.00', $attempt->refresh()->score);
    }

    public function test_les_qcm_d_une_evaluation_corrigee_a_la_main_ne_sont_pas_notes_automatiquement(): void
    {
        $this->quiz = $this->makeQuiz(['manual_choice_grading' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([0]);
        $answer = $this->answerFor($attempt, $question);

        $this->assertNull($answer->points_awarded);
        $this->assertNull($answer->is_correct);

        // Corriger le barème ne note rien : le total « sur combien » suit, la
        // décision reste à l'enseignant.
        $this->saveQuestion($question, ['points' => 4])->assertRedirect();

        $this->assertNull($answer->refresh()->points_awarded);
        $this->assertNull($answer->is_correct);
        $this->assertSame('4.00', $attempt->refresh()->max_score);
        $this->assertTrue($attempt->awaitsManualGrading());
    }

    public function test_une_question_devenue_redigee_renvoie_ses_copies_en_correction(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 3);
        $attempt = $this->playCopy([0]);

        $this->assertSame('3.00', $attempt->refresh()->score);
        $this->assertTrue($attempt->isFullyGraded());

        $this->saveQuestion($question, [
            'field_type' => FormField::OPEN_TYPE,
            'expected_answer' => 'Un raisonnement structuré.',
        ])->assertRedirect();

        $answer = $this->answerFor($attempt, $question);

        // La machine ne peut plus trancher : la note redevient « en attente », et
        // la copie entre dans la file de correction — jamais « zéro », qui
        // passerait pour une décision de l'enseignant.
        $this->assertNull($answer->points_awarded);
        $this->assertNull($answer->is_correct);
        $this->assertSame(1, $attempt->refresh()->pendingManualCount());
        $this->assertTrue($attempt->awaitsManualGrading());
        $this->assertSame('0.00', $attempt->score);
    }

    // -------------------------------------------------------- Rien d'inutile

    public function test_modifier_le_seul_enonce_ne_reecrit_aucune_note(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([0]);
        $answer = $this->answerFor($attempt, $question);

        $answerTouchedAt = $answer->updated_at;
        $attemptTouchedAt = $attempt->updated_at;

        // Cinq minutes plus tard : une écriture laisserait une trace visible.
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:05:00'));

        $this->saveQuestion($question, ['field_label' => 'La Terre est bien ronde.'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Question mise à jour.');

        $this->assertSame('2.00', $answer->refresh()->points_awarded);
        $this->assertTrue($answer->updated_at->equalTo($answerTouchedAt));
        $this->assertTrue($attempt->refresh()->updated_at->equalTo($attemptTouchedAt));
    }

    public function test_renoter_deux_fois_la_meme_question_ne_change_rien_la_seconde_fois(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([0]);

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $answer = $this->answerFor($attempt, $question);
        $answerTouchedAt = $answer->updated_at;
        $attemptTouchedAt = $attempt->updated_at;

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:05:00'));

        // Le même enregistrement, une seconde fois : la question n'a pas changé,
        // les notes non plus.
        $this->saveQuestion($question, ['correct' => [1]])
            ->assertRedirect()
            ->assertSessionHas('success', 'Question mise à jour.');

        $this->assertSame('0.00', $answer->refresh()->points_awarded);
        $this->assertTrue($answer->updated_at->equalTo($answerTouchedAt));
        $this->assertTrue($attempt->refresh()->updated_at->equalTo($attemptTouchedAt));
    }

    public function test_renoter_une_promotion_entiere_ne_coute_pas_une_requete_par_copie(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $now = Carbon::now();

        // Deux cent cinquante copies rendues, montées en lot : ce qui est mesuré
        // ici est le coût de la renotation, pas celui du montage du test.
        $attempts = [];

        for ($i = 1; $i <= 250; $i++) {
            $attempts[] = [
                'form_id' => $this->quiz->id,
                'reference' => 'PROMO'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'status' => QuizAttempt::STATUS_SUBMITTED,
                'started_at' => $now->copy()->subMinutes(30),
                'submitted_at' => $now,
                'score' => 2,
                'max_score' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        QuizAttempt::insert($attempts);

        $answers = [];

        foreach (QuizAttempt::pluck('id') as $id) {
            $answers[] = [
                'quiz_attempt_id' => $id,
                'form_field_id' => $question->id,
                'choice' => json_encode([0]),
                'is_correct' => true,
                'points_awarded' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        QuizAnswer::insert($answers);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->saveQuestion($question, ['correct' => [1]])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '250 copie(s) rendue(s) renotée(s)'));

        // Les écritures suivent les paquets et les totaux distincts, jamais
        // l'effectif : 250 copies ne doivent pas coûter 250 requêtes. Le plafond
        // est large — il attrape un N+1, pas une variation de style.
        $this->assertLessThan(60, $queries, 'La renotation ne doit pas faire une requête par copie.');

        $this->assertSame('0.00', QuizAttempt::firstOrFail()->score);
        $this->assertSame(0, QuizAnswer::where('points_awarded', '>', 0)->count());
    }

    // ------------------------------------------------------------- Ce qu'on dit

    public function test_la_renotation_est_annoncee_a_l_enseignant(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        // Évaluation encore ouverte : les étudiants n'ont rien vu, le message se
        // contente de dire ce qui a changé.
        $this->saveQuestion($question, ['correct' => [1]])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'renotée')
                && ! str_contains($message, 'voyaient déjà leur note'));

        // Fermée : ils ont pu lire leur note, et la changer le regarde.
        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $this->saveQuestion($question, ['correct' => [0]])
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'voyaient déjà leur note'));
    }

    public function test_l_ecran_de_configuration_previent_que_les_copies_seront_renotees(): void
    {
        $this->choiceQuestion();

        // La renotation ne doit pas être une surprise : elle est annoncée là où
        // l'enseignant corrige la question, et son absence de portée sur le
        // travail humain est dite dans la même phrase.
        $this->get(route('admin.quizzes.show', $this->quiz))
            ->assertOk()
            ->assertSee('renote les copies déjà rendues', false)
            ->assertSee('jamais touchée', false);
    }

    public function test_supprimer_une_question_remet_le_total_des_copies_d_aplomb(): void
    {
        $kept = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $removed = $this->choiceQuestion(['Un', 'Deux'], [1], 1);

        $attempt = $this->playCopy([0, 1]);

        $this->assertSame('3.00', $attempt->refresh()->score);
        $this->assertSame('3.00', $attempt->max_score);

        $this->delete(route('admin.quizzes.questions.destroy', [$this->quiz, $removed]))->assertRedirect();

        // La réponse disparaît avec sa question : le total ne doit plus la
        // compter, sinon la copie resterait notée sur un barème qui n'existe plus.
        $this->assertSame(0, $attempt->answers()->where('form_field_id', $removed->id)->count());
        $this->assertSame('2.00', $attempt->refresh()->score);
        $this->assertSame('2.00', $attempt->max_score);
        $this->assertNotNull($kept->fresh());
    }

    public function test_la_renotation_ne_sort_pas_de_l_evaluation(): void
    {
        $other = $this->makeQuiz();

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $otherQuestion = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2, $other);

        $this->playCopy([0]);
        $otherAttempt = $this->playCopy([0], $other);

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $otherAnswer = $this->answerFor($otherAttempt, $otherQuestion);

        $this->assertSame('2.00', $otherAnswer->points_awarded);
        $this->assertTrue($otherAnswer->is_correct);
        $this->assertSame('2.00', $otherAttempt->refresh()->score);
    }

    // --------------------------------------------------------- Le rattrapage

    public function test_le_recalcul_rattrape_les_notes_calculees_avant_la_renotation(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([0]);

        // Une configuration corrigée sans passer par l'écran : c'est l'état que
        // ce bouton existe pour rattraper — la note enregistrée contredit la
        // bonne réponse, et rien ne l'a signalé.
        $question->update(['correct_answer' => [1]]);

        $this->assertSame('2.00', $this->answerFor($attempt, $question)->points_awarded);

        // Évaluation fermée : les étudiants ont déjà lu leur note, et le message
        // doit le dire au lieu de la changer en silence.
        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $this->post(route('admin.quizzes.regrade', $this->quiz))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 copie(s) rendue(s) renotée(s)')
                && str_contains($message, 'voyaient déjà leur note'));

        $answer = $this->answerFor($attempt, $question);

        $this->assertSame('0.00', $answer->points_awarded);
        $this->assertFalse($answer->is_correct);
        $this->assertSame('0.00', $attempt->refresh()->score);
    }

    public function test_le_recalcul_ne_touche_pas_aux_notes_posees_a_la_main(): void
    {
        $this->quiz = $this->makeQuiz(['manual_choice_grading' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([1]);
        $answer = $this->answerFor($attempt, $question);

        $this->post(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
        ])->assertRedirect();

        $this->post(route('admin.quizzes.regrade', $this->quiz))->assertRedirect();

        $answer->refresh();

        // Le rattrapage passe par le même filtre d'auteur : la note signée reste.
        $this->assertSame('2.00', $answer->points_awarded);
        $this->assertSame($this->admin->id, $answer->graded_by_admin_id);
    }

    public function test_le_recalcul_dit_quand_tout_est_deja_a_jour(): void
    {
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        // Rien à rattraper : le message le dit, plutôt que de laisser croire à un
        // travail qui n'a pas eu lieu.
        $this->post(route('admin.quizzes.regrade', $this->quiz))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'déjà à jour'));
    }

    public function test_le_bouton_de_recalcul_est_propose_avec_les_copies(): void
    {
        $this->choiceQuestion();
        $this->playCopy([0]);

        // Le geste est visible là où l'enseignant lit ses résultats, et le
        // garde-fou annoncé avant le clic est celui du module : les notes
        // humaines ne bougent pas. Le bouton n'écrit plus depuis cette page :
        // il ouvre l'aperçu, où la confirmation se demande une fois les notes
        // concernées annoncées.
        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('Recalculer les notes')
            ->assertSee('ne sont pas touchées', false)
            ->assertSee(route('admin.quizzes.regrade.preview', $this->quiz), false);
    }

    // ------------------------------------------------------------- L'aperçu

    public function test_l_apercu_annonce_les_notes_qui_changeraient_sans_rien_ecrire(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $right = $this->playCopy([0]);

        // Une copie rendue blanche : la question n'a produit aucune réponse pour
        // elle, sa note ne peut donc pas bouger.
        $blank = $this->quiz->attempts()->create([
            'reference' => 'BLANKDEPRE',
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 0,
            'max_score' => 2,
        ]);

        // Configuration corrigée hors écran : c'est l'état que le rattrapage
        // existe pour reprendre.
        $question->update(['correct_answer' => [1]]);

        $this->get(route('admin.quizzes.regrade.preview', $this->quiz))
            ->assertOk()
            ->assertSee('1 copie(s) sur 2')
            ->assertSee($right->reference)
            // La copie dont la note ne bouge pas n'a rien à faire dans un aperçu.
            ->assertDontSee($blank->reference)
            ->assertSee('Confirmer le recalcul');

        // L'aperçu n'a rien écrit : les notes annoncées comme « avant » sont
        // encore celles que les étudiants lisent.
        $answer = $this->answerFor($right, $question);

        $this->assertSame('2.00', $answer->points_awarded);
        $this->assertSame('2.00', $right->refresh()->score);

        // Et la confirmation fait exactement ce qui venait d'être annoncé.
        $this->post(route('admin.quizzes.regrade', $this->quiz))
            ->assertRedirect(route('admin.quizzes.results', $this->quiz))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 copie(s) rendue(s) renotée(s)'));

        $this->assertSame('0.00', $answer->refresh()->points_awarded);
        $this->assertSame('0.00', $right->refresh()->score);
    }

    public function test_l_apercu_dit_quand_rien_ne_changerait(): void
    {
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        $this->get(route('admin.quizzes.regrade.preview', $this->quiz))
            ->assertOk()
            ->assertSee('Aucun changement')
            // Rien à confirmer : le bouton qui écrit n'est pas proposé.
            ->assertDontSee('Confirmer le recalcul');
    }

    public function test_l_apercu_compte_a_part_les_renotations_qui_ne_deplacent_aucune_note(): void
    {
        $first = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $second = $this->choiceQuestion(['Un', 'Deux'], [1], 2);

        $attempt = $this->playCopy([0, 0]);

        $this->assertSame('2.00', $attempt->refresh()->score);
        $this->assertSame('4.00', $attempt->max_score);

        // Les deux bonnes réponses s'inversent : le candidat perd la première et
        // gagne la seconde. La machine change deux fois, sa note ne bouge pas —
        // et pourtant le verdict de chacune des deux questions change sur sa
        // copie. L'aperçu le dit, et reste donc confirmable.
        $first->update(['correct_answer' => [1]]);
        $second->update(['correct_answer' => [0]]);

        $this->get(route('admin.quizzes.regrade.preview', $this->quiz))
            ->assertOk()
            ->assertSee('Aucune note ne bouge')
            ->assertSee('2 réponse(s) seraient renotées')
            ->assertSee('Confirmer le recalcul');

        $this->post(route('admin.quizzes.regrade', $this->quiz))->assertRedirect();

        $this->assertSame('2.00', $attempt->refresh()->score);
        $this->assertFalse($this->answerFor($attempt, $first)->is_correct);
        $this->assertTrue($this->answerFor($attempt, $second)->is_correct);
    }

    public function test_l_apercu_ne_touche_pas_aux_notes_posees_a_la_main(): void
    {
        $this->quiz = $this->makeQuiz(['manual_choice_grading' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([1]);
        $answer = $this->answerFor($attempt, $question);

        $this->post(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
        ])->assertRedirect();

        // La bonne réponse change : la note signée reste hors du recalcul, et
        // l'aperçu doit donc se taire plutôt que de l'annoncer comme changeante.
        $question->update(['correct_answer' => [0]]);

        $this->get(route('admin.quizzes.regrade.preview', $this->quiz))
            ->assertOk()
            ->assertSee('Aucun changement')
            ->assertDontSee('Confirmer le recalcul');

        $this->assertSame('2.00', $answer->refresh()->points_awarded);
    }

    public function test_l_apercu_annonce_les_copies_qui_repartent_en_correction(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 3);
        $attempt = $this->playCopy([0]);

        // Question devenue rédigée : la machine ne peut plus trancher, la note
        // automatique tombe, et la copie repart en correction.
        $question->update(['field_type' => FormField::OPEN_TYPE]);

        $this->get(route('admin.quizzes.regrade.preview', $this->quiz))
            ->assertOk()
            ->assertSee('repasse en correction')
            ->assertSee('repartiraient en correction')
            ->assertSee('Confirmer le recalcul');

        // Là encore, rien n'est encore effacé avant la confirmation.
        $this->assertSame('3.00', $this->answerFor($attempt, $question)->points_awarded);
        $this->assertSame('3.00', $attempt->refresh()->score);
    }

    public function test_l_apercu_montre_le_bareme_qui_change_meme_sans_reponse(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);

        // Une copie rendue sans une seule réponse : aucune note ne bouge, mais
        // son total « sur combien » dépend du barème de la question.
        $blank = $this->quiz->attempts()->create([
            'reference' => 'BLANKAPERC',
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 0,
            'max_score' => 2,
        ]);

        $question->update(['points' => 5]);

        $this->get(route('admin.quizzes.regrade.preview', $this->quiz))
            ->assertOk()
            ->assertSee('BLANKAPERC')
            ->assertSee('barème seul')
            ->assertSee('barème : 2 → 5')
            ->assertSee('Confirmer le recalcul');

        $this->assertSame('2.00', $blank->refresh()->max_score);
    }

    public function test_le_bouton_disparait_sans_copie(): void
    {
        $this->choiceQuestion();

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertDontSee('Recalculer les notes');
    }

    // ------------------------------------ L'aperçu avant l'enregistrement

    /**
     * Demande l'aperçu de la renotation comme le fait l'écran de configuration :
     * le payload complet part de l'état enregistré, et seules les valeurs testées
     * sont remplacées.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function previewQuestion(FormField $question, array $overrides = [], ?Form $quiz = null): TestResponse
    {
        $quiz ??= $this->quiz;

        return $this->postJson(route('admin.quizzes.questions.renotation', [$quiz, $question]), array_merge([
            'field_label' => $question->field_label,
            'field_type' => $question->field_type,
            'options' => $question->getOptionsList(),
            'correct' => $question->correctIndexes(),
            'points' => (float) $question->points,
            'expected_answer' => $question->expected_answer,
        ], $overrides));
    }

    /**
     * L'aperçu tel que l'enseignant le lit : les balises retirées, les espaces
     * normalisés. Le panneau est de la mise en forme — ce qui compte, c'est la
     * phrase et les chiffres.
     */
    private function previewText(FormField $question, array $overrides = [], ?Form $quiz = null): string
    {
        $html = $this->previewQuestion($question, $overrides, $quiz)->assertOk()->json('html');

        $this->assertIsString($html);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    public function test_l_apercu_avant_enregistrement_annonce_les_copies_concernes(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $attempt = $this->playCopy([0]);

        // L'enseignant s'apprête à désigner « Faux » : sa copie perd ses points.
        $text = $this->previewText($question, ['correct' => [1]]);

        $this->assertStringContainsString('1 copie(s) sur 1', $text);
        $this->assertStringContainsString($attempt->reference, $text);
        $this->assertStringContainsString('note 4 → 0 / 4', $text);

        // L'aperçu compte, il n'écrit pas : la copie est encore notée comme
        // avant, et c'est ce que les étudiants lisent à cet instant.
        $answer = $this->answerFor($attempt, $question);

        $this->assertSame('4.00', $answer->points_awarded);
        $this->assertTrue($answer->is_correct);
        $this->assertSame('4.00', $attempt->refresh()->score);

        // Et ce qu'il annonçait est bien ce que l'enregistrement fait.
        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $this->assertSame('0.00', $answer->refresh()->points_awarded);
        $this->assertSame('0.00', $attempt->refresh()->score);
    }

    public function test_l_apercu_avant_enregistrement_se_tait_sur_une_definition_identique(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        // Rien n'a été modifié : la question telle qu'elle est enregistrée n'a
        // par définition rien à renoter.
        $this->assertStringContainsString('Aucun changement', $this->previewText($question));
    }

    public function test_l_apercu_avant_enregistrement_dit_les_copies_qui_repartent_en_correction(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 3);
        $attempt = $this->playCopy([0]);

        // Passage en réponse rédigée : la machine ne tranche plus cette
        // question, la copie repart en correction.
        $text = $this->previewText($question, [
            'field_type' => FormField::OPEN_TYPE,
            'expected_answer' => 'Rédiger une réponse.',
        ]);

        $this->assertStringContainsString('repasseraient en correction', $text);

        // La note automatique est encore là : elle ne tombera qu'à
        // l'enregistrement.
        $this->assertSame('3.00', $this->answerFor($attempt, $question)->points_awarded);
        $this->assertSame('3.00', $attempt->refresh()->score);
    }

    public function test_l_apercu_avant_enregistrement_ne_touche_pas_aux_notes_humaines(): void
    {
        $this->quiz = $this->makeQuiz(['manual_choice_grading' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([1]);
        $answer = $this->answerFor($attempt, $question);

        $this->post(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
        ])->assertRedirect();

        // La bonne réponse change : la note signée reste hors du calcul, et
        // l'aperçu doit donc se taire plutôt que de l'annoncer comme changeante.
        $text = $this->previewText($question, ['correct' => [0]]);

        $this->assertStringContainsString('Aucun changement', $text);
        $this->assertStringNotContainsString($attempt->reference, $text);
    }

    public function test_l_apercu_avant_enregistrement_est_cloisonne_par_evaluation(): void
    {
        $other = $this->makeQuiz();
        $otherQuestion = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2, $other);

        // La question d'une autre évaluation ne s'aperçoit pas depuis celle-ci,
        // pas plus qu'un champ qui n'est pas une question.
        $this->previewQuestion($otherQuestion)
            ->assertNotFound();

        $this->postJson(route('admin.quizzes.questions.renotation', [$this->quiz, $otherQuestion]))
            ->assertNotFound();

        $this->postJson(route('admin.quizzes.questions.renotation', [$this->quiz, $this->quiz->fields()->create([
            'field_label' => 'Nom complet',
            'field_type' => 'text',
            'required' => true,
            'order' => 1,
        ])]))->assertNotFound();
    }

    public function test_l_apercu_avant_enregistrement_refuse_ce_que_l_enregistrement_refuse(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([0]);

        // Aucune bonne réponse désignée : la validation est celle de
        // l'enregistrement, et l'aperçu la rend telle quelle. Un formulaire
        // incomplet n'a pas d'effet à annoncer.
        $this->previewQuestion($question, ['correct' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correct');

        $this->assertSame('2.00', $this->answerFor($attempt, $question)->points_awarded);
        $this->assertSame('2.00', $attempt->refresh()->score);
    }

    public function test_l_ecran_de_configuration_propose_l_apercu_avant_enregistrement(): void
    {
        $question = $this->choiceQuestion();
        $this->playCopy([0]);

        // Le panneau est là, avec l'adresse que le formulaire interroge. Le
        // script, lui, est poussé pour toutes les pages : c'est cet attribut qui
        // dit que l'aperçu de cette question est proposé.
        $this->get(route('admin.quizzes.show', $this->quiz))
            ->assertOk()
            ->assertSee('data-renotation-url="'.route('admin.quizzes.questions.renotation', [$this->quiz, $question]).'"', false);

        // Sans copie rendue, il n'y a personne à prévenir : le panneau n'est pas
        // rendu du tout, plutôt que d'annoncer un effet sur zéro copie.
        $fresh = $this->makeQuiz();
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2, $fresh);

        $this->get(route('admin.quizzes.show', $fresh))
            ->assertOk()
            ->assertDontSee('data-renotation-url=', false);
    }
}

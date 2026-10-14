<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizRegradeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Journal des recalculs : qui les a lancés, quand, et sur combien de copies.
 *
 * C'est la mémoire des notes qui ont bougé **sans qu'un humain ne les signe**.
 * Quatre garanties tiennent ce fichier :
 *
 *   1. les trois gestes qui renotent laissent une trace — le recalcul demandé
 *      depuis les résultats, la renotation d'une question modifiée, la remise
 *      d'aplomb d'une question supprimée —, avec leur auteur et leur effet ;
 *   2. le compte écrit est celui qui a été annoncé à l'enseignant : un recalcul
 *      demandé se trace même sans effet, une question enregistrée non ;
 *   3. rien n'y entre au nom d'un humain : une note signée n'étant jamais
 *      recalculée, elle ne produit aucune ligne ;
 *   4. la trace survit à ce qu'elle nomme — la suppression d'un compte comme
 *      celle d'une question laissent la ligne lisible.
 */
class QuizRegradeJournalTest extends TestCase
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
            'title' => 'Évaluation journalisée',
            'token' => 'JETON-JOURNAL-'.uniqid(),
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
     * Joue une copie entière par les routes réelles, puis libère le poste.
     *
     * @param  array<int, int|array<int, int>>  $choices
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

        $this->post(route('quiz.new-candidate', $quiz->token));

        return $attempt;
    }

    /**
     * Enregistre la question comme le ferait l'écran « Modifier cette question ».
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

    private function journal(?Form $quiz = null): TestResponse
    {
        return $this->get(route('admin.quizzes.regrades', $quiz ?? $this->quiz));
    }

    private function runPage(QuizRegradeRun $run, ?Form $quiz = null): TestResponse
    {
        return $this->get(route('admin.quizzes.regrades.show', [$quiz ?? $this->quiz, $run]));
    }

    /**
     * La page d'un recalcul telle qu'elle se lit : balises retirées, espaces
     * normalisés. Ce qui compte est la référence, le nom et les notes, pas la
     * mise en forme des cellules.
     */
    private function runPageText(QuizRegradeRun $run, ?Form $quiz = null): string
    {
        $html = (string) $this->runPage($run, $quiz)->assertOk()->getContent();

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    // ---------------------------------------------------- Ce qui laisse une trace

    public function test_le_recalcul_demande_est_journalise_avec_son_auteur_et_son_effet(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        // Configuration corrigée hors écran : c'est le cas que le rattrapage
        // existe pour reprendre.
        $question->update(['correct_answer' => [1]]);

        $this->post(route('admin.quizzes.regrade', $this->quiz))
            ->assertRedirect(route('admin.quizzes.results', $this->quiz));

        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame(QuizRegradeRun::ORIGIN_QUIZ, $run->origin);
        $this->assertSame($this->admin->id, $run->admin_user_id);
        $this->assertSame(1, $run->copies);
        // Le recalcul porte sur toutes les questions : aucune n'est désignée.
        $this->assertNull($run->form_field_id);
        $this->assertNull($run->question_label);
        $this->assertSame('admin', $run->actorLabel());

        $this->journal()
            ->assertOk()
            ->assertSee('Recalcul demandé')
            ->assertSee('admin')
            ->assertSee('admin@test.com')
            ->assertSee('toutes les questions')
            ->assertSee('1 passe(s) de renotation enregistrée(s)');
    }

    public function test_l_enregistrement_d_une_question_laisse_une_trace_avec_son_enonce(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $this->playCopy([0]);

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame(QuizRegradeRun::ORIGIN_QUESTION, $run->origin);
        $this->assertSame($this->admin->id, $run->admin_user_id);
        $this->assertSame($question->id, $run->form_field_id);
        $this->assertSame('La Terre est ronde.', $run->question_label);
        $this->assertSame(1, $run->copies);

        $this->journal()
            ->assertOk()
            ->assertSee('Question modifiée')
            ->assertSee('La Terre est ronde.');
    }

    public function test_la_suppression_d_une_question_laisse_son_enonce_au_journal(): void
    {
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $removed = $this->choiceQuestion(['Un', 'Deux'], [1], 1);
        $removed->update(['field_label' => 'Question à retirer']);

        $attempt = $this->playCopy([0, 1]);

        $this->assertSame('3.00', $attempt->refresh()->max_score);

        $this->delete(route('admin.quizzes.questions.destroy', [$this->quiz, $removed]))->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame(QuizRegradeRun::ORIGIN_DELETED, $run->origin);
        // La question a disparu : il ne reste ni identifiant, ni relation — mais
        // son énoncé est recopié, et c'est ce qui rend la ligne lisible.
        $this->assertNull($run->form_field_id);
        $this->assertSame('Question à retirer', $run->question_label);
        $this->assertSame(1, $run->copies);
        $this->assertSame('2.00', $attempt->refresh()->max_score);

        $this->journal()
            ->assertOk()
            ->assertSee('Question supprimée')
            ->assertSee('Question à retirer');
    }

    // ------------------------------------------------ Ce qui n'en laisse pas

    public function test_une_question_enregistree_sans_effet_sur_les_copies_n_est_pas_journalisee(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        // Seul l'énoncé change : la renotation ne fait que passer, et corriger un
        // énoncé n'est pas un recalcul à porter au journal.
        $this->saveQuestion($question, ['field_label' => 'La Terre est ronde (relue).'])->assertRedirect();

        $this->assertSame(0, QuizRegradeRun::count());

        // L'apostrophe est écrite en clair dans la vue : la comparaison se fait
        // donc sans échappement.
        $this->journal()
            ->assertOk()
            ->assertSee('Aucun recalcul pour l\'instant', false);
    }

    public function test_une_copie_notee_a_la_main_ne_compte_pas_comme_renotee(): void
    {
        $this->quiz = $this->makeQuiz(['manual_choice_grading' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $attempt = $this->playCopy([1]);
        $answer = $this->answerFor($attempt, $question);

        // L'enseignant note le QCM lui-même, contre l'avis de la machine.
        $this->post(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
        ])->assertRedirect();

        // La bonne réponse change : la note signée reste hors du recalcul, aucune
        // copie ne bouge — et le journal n'a donc rien à dire.
        $this->saveQuestion($question, ['correct' => [0]])->assertRedirect();

        $this->assertSame(0, QuizRegradeRun::count());
        $this->assertSame('2.00', $answer->refresh()->points_awarded);
    }

    public function test_le_recalcul_demande_est_journalise_meme_sans_effet(): void
    {
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        // Rien à rattraper : le geste reste un geste, et le journal le dit
        // plutôt que de laisser croire qu'il n'a jamais été lancé.
        $this->post(route('admin.quizzes.regrade', $this->quiz))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'déjà à jour'));

        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame(QuizRegradeRun::ORIGIN_QUIZ, $run->origin);
        $this->assertSame(0, $run->copies);

        $this->journal()
            ->assertOk()
            ->assertSee('aucune')
            ->assertSee('1 passe(s) de renotation enregistrée(s)');
    }

    // ------------------------------------------------------------------ Le journal

    public function test_le_journal_ne_sort_pas_de_l_evaluation(): void
    {
        $other = $this->makeQuiz();
        $otherQuestion = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2, $other);

        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);
        $this->playCopy([0], $other);

        // Le recalcul est demandé sur l'autre évaluation, et une de ses questions
        // vient d'être corrigée hors écran.
        $otherQuestion->update(['correct_answer' => [1]]);
        $this->post(route('admin.quizzes.regrade', $other))->assertRedirect();

        $this->assertSame(1, QuizRegradeRun::where('form_id', $other->id)->count());

        $this->journal()->assertOk()->assertSee('Aucun recalcul pour l\'instant', false);

        $this->get(route('admin.quizzes.regrades', $other))
            ->assertOk()
            ->assertSee('Recalcul demandé');
    }

    public function test_le_journal_survit_a_la_suppression_du_compte(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $this->playCopy([0]);

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        // Une évaluation ne reste pas orpheline : elle est reprise par un autre
        // compte avant que son auteur ne soit supprimé.
        $successor = AdminUser::create([
            'username' => 'successeur',
            'email' => 'successeur@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->quiz->update(['created_by' => $successor->id]);
        $this->admin->delete();

        // La ligne reste, et dit ce qu'elle peut dire : l'auteur a disparu, le
        // recalcul qu'il a lancé, non.
        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame($this->admin->id, $run->admin_user_id);
        $this->assertSame('compte supprimé', $run->actorLabel());

        // Le journal est lu par son successeur : la session du compte supprimé
        // est refusée par le contrôle d'accès, comme elle doit l'être.
        $this->session(['admin_user' => [
            'id' => $successor->id,
            'username' => $successor->username,
            'email' => $successor->email,
            'role' => $successor->role,
        ]]);

        $this->journal()
            ->assertOk()
            ->assertSee('compte supprimé')
            ->assertSee('Question modifiée');
    }

    // ------------------------------------------------------- Le détail des copies

    public function test_le_recalcul_demande_conserve_la_note_avant_et_apres(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $attempt = $this->playCopy([0]);

        $this->assertSame('4.00', $attempt->refresh()->score);

        $question->update(['correct_answer' => [1]]);

        $this->post(route('admin.quizzes.regrade', $this->quiz))->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        // Le détail est celui de l'aperçu, lu avant l'écriture : il ne peut
        // annoncer autre chose que ce que le recalcul a fait.
        $this->assertSame([[
            'attempt_id' => $attempt->id,
            'reference' => $attempt->reference,
            'from' => 4.0,
            'to' => 0.0,
            'max_from' => 4.0,
            'max_to' => 4.0,
            'answers' => 1,
            'released' => 0,
            'reclaimed' => 0,
        ]], $run->changedCopies());

        $text = $this->runPageText($run);

        $this->assertStringContainsString($attempt->reference, $text);
        $this->assertStringContainsString('Candidat Test', $text);
        $this->assertStringContainsString('4 / 4', $text);
        $this->assertStringContainsString('0 / 4', $text);
        $this->assertStringContainsString('1 réponse(s) renotée(s)', $text);

        $this->assertSame('0.00', $attempt->refresh()->score);
    }

    public function test_l_enregistrement_d_une_question_conserve_aussi_l_avant_et_l_apres(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $attempt = $this->playCopy([0]);

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame([[
            'attempt_id' => $attempt->id,
            'reference' => $attempt->reference,
            'from' => 4.0,
            'to' => 0.0,
            'max_from' => 4.0,
            'max_to' => 4.0,
            'answers' => 1,
            'released' => 0,
            'reclaimed' => 0,
        ]], $run->changedCopies());

        $text = $this->runPageText($run);

        // L'énoncé d'alors est rappelé en tête : c'est lui qui a été renoté.
        $this->assertStringContainsString('La Terre est ronde.', $text);
        $this->assertStringContainsString('Question modifiée', $text);
        $this->assertStringContainsString('4 / 4', $text);
        $this->assertStringContainsString('0 / 4', $text);
    }

    public function test_la_suppression_d_une_question_conserve_l_avant_et_l_apres(): void
    {
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $removed = $this->choiceQuestion(['Un', 'Deux'], [1], 1);

        $attempt = $this->playCopy([0, 1]);

        $this->assertSame('3.00', $attempt->refresh()->score);
        $this->assertSame('3.00', $attempt->max_score);

        $this->delete(route('admin.quizzes.questions.destroy', [$this->quiz, $removed]))->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        // La copie perd la note de la question et son barème : c'est ce que la
        // suppression a réellement fait, ligne pour ligne.
        $this->assertSame([[
            'attempt_id' => $attempt->id,
            'reference' => $attempt->reference,
            'from' => 3.0,
            'to' => 2.0,
            'max_from' => 3.0,
            'max_to' => 2.0,
            'answers' => 1,
            'released' => 0,
            'reclaimed' => 0,
        ]], $run->changedCopies());

        $this->assertSame('2.00', $attempt->refresh()->score);
        $this->assertSame('2.00', $attempt->max_score);

        $this->assertStringContainsString(
            '1 réponse(s) retirée(s) avec la question',
            $this->runPageText($run),
        );
    }

    public function test_la_copie_qui_repart_en_correction_est_dite_au_detail(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 3);
        $attempt = $this->playCopy([0]);

        // Question devenue « réponse rédigée » : la machine ne tranche plus, la
        // copie repart en correction.
        $this->saveQuestion($question, [
            'field_type' => FormField::OPEN_TYPE,
            'expected_answer' => 'Rédiger une réponse.',
        ])->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        $this->assertSame(1, $run->changedCopies()[0]['released']);
        $this->assertSame(3.0, $run->changedCopies()[0]['from']);
        $this->assertSame(0.0, $run->changedCopies()[0]['to']);

        $this->assertStringContainsString('repasse en correction', $this->runPageText($run));
        $this->assertNull($this->answerFor($attempt, $question)->points_awarded);
    }

    public function test_le_detail_d_une_evaluation_anonyme_ne_porte_pas_les_noms(): void
    {
        $this->quiz->update(['is_anonymous' => true]);

        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $attempt = $this->playCopy([0]);

        $question->update(['correct_answer' => [1]]);
        $this->post(route('admin.quizzes.regrade', $this->quiz))->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        // Le journal d'une épreuve anonyme ne devient pas le registre des noms.
        $this->assertStringNotContainsString('Candidat Test', (string) json_encode($run->copies_detail));

        $text = $this->runPageText($run);

        $this->assertStringContainsString($attempt->reference, $text);
        $this->assertStringNotContainsString('Candidat Test', $text);
        $this->assertStringNotContainsString('Étudiant', $text);
    }

    public function test_le_detail_reste_lisible_quand_la_participation_est_supprimee(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $attempt = $this->playCopy([0]);
        $reference = $attempt->reference;

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        $this->delete(route('admin.quizzes.attempts.destroy', [$this->quiz, $attempt]))->assertRedirect();

        $this->assertNull(QuizAttempt::find($attempt->id));

        $text = $this->runPageText($run);

        // La ligne reste : elle dit ce qui s'est passé, avec la référence qu'avait
        // la copie. Le lien, lui, n'a plus où mener.
        $this->assertStringContainsString($reference, $text);
        $this->assertStringContainsString('copie supprimée', $text);
        $this->assertStringNotContainsString('/attempts/'.$attempt->id.'/grade', $text);
    }

    public function test_un_recalcul_sans_detail_dit_qu_il_n_a_pas_ete_conserve(): void
    {
        // Un recalcul antérieur au journal des copies : le compte est là, la
        // liste non.
        $run = QuizRegradeRun::create([
            'form_id' => $this->quiz->id,
            'admin_user_id' => $this->admin->id,
            'origin' => QuizRegradeRun::ORIGIN_QUIZ,
            'copies' => 3,
            'copies_detail' => null,
        ]);

        $text = $this->runPageText($run);

        $this->assertStringContainsString('n\'a pas été conservé', $text);
        $this->assertStringContainsString('on sait combien de notes ont changé (3), pas lesquelles', $text);

        // Le journal ne mène pas à une liste qui n'existe pas.
        $this->journal()
            ->assertOk()
            ->assertDontSee(route('admin.quizzes.regrades.show', [$this->quiz, $run]), false);
    }

    public function test_le_journal_mene_a_la_liste_des_copies(): void
    {
        $question = $this->choiceQuestion(['Vrai', 'Faux'], [0], 4);
        $this->playCopy([0]);

        $this->saveQuestion($question, ['correct' => [1]])->assertRedirect();

        $run = QuizRegradeRun::firstOrFail();

        $this->journal()
            ->assertOk()
            ->assertSee(route('admin.quizzes.regrades.show', [$this->quiz, $run]), false);

        // Un recalcul sans effet n'a aucune liste à montrer : le compte y reste
        // du texte, pas un lien.
        $this->post(route('admin.quizzes.regrade', $this->quiz))->assertRedirect();

        $empty = QuizRegradeRun::orderByDesc('id')->firstOrFail();

        $this->assertSame(0, $empty->copies);
        $this->assertSame([], $empty->copies_detail);

        $this->journal()
            ->assertOk()
            ->assertDontSee(route('admin.quizzes.regrades.show', [$this->quiz, $empty]), false);
    }

    public function test_le_detail_d_un_recalcul_ne_sort_pas_de_l_evaluation(): void
    {
        $other = $this->makeQuiz();
        $otherQuestion = $this->choiceQuestion(['Vrai', 'Faux'], [0], 2, $other);

        $this->playCopy([0], $other);
        $otherQuestion->update(['correct_answer' => [1]]);

        $this->post(route('admin.quizzes.regrade', $other))->assertRedirect();

        $run = QuizRegradeRun::where('form_id', $other->id)->firstOrFail();

        // Le recalcul d'une autre évaluation ne se lit pas depuis celle-ci.
        $this->runPage($run)->assertNotFound();
    }

    public function test_le_journal_se_lit_depuis_les_resultats(): void
    {
        $this->choiceQuestion(['Vrai', 'Faux'], [0], 2);
        $this->playCopy([0]);

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('Journal des recalculs')
            ->assertSee(route('admin.quizzes.regrades', $this->quiz), false);

        // Sans copie rendue, aucune note ne peut avoir bougé : il n'y a rien à
        // consulter, et le lien n'est pas proposé.
        $fresh = $this->makeQuiz();

        $this->get(route('admin.quizzes.results', $fresh))
            ->assertOk()
            ->assertDontSee('Journal des recalculs');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizGradeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Le second niveau de relecture.
 *
 * Une note posée par un correcteur externe n'est pas un point final :
 * l'administration peut la reprendre. Trois choses doivent alors tenir, et
 * chacune a ses tests ici :
 *   1. elle doit dire **pourquoi** — sans motif, la trace ne dirait rien ;
 *   2. la note remplacée est **archivée**, jamais effacée ;
 *   3. la reprise est **définitive** du point de vue du correcteur, qui ne peut
 *      plus écraser la décision de l'administration.
 */
class QuizGradeReviewTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    private Grader $grader;

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

        $this->quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-REVUE',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
                'requires_reference' => false,
            ],
            'created_by' => $this->admin->id,
        ]);

        $this->grader = Grader::createFor('Awa Kouassi', 'awa@test.com', Carbon::now()->addDays(3), $this->admin->id);
        $this->grader->forms()->attach($this->quiz->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function openQuestion(): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Expliquez la saponification en deux ou trois lignes.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => 'Huile et soude donnent du savon et de la glycérine.',
            'points' => 3,
        ]);
    }

    private function playCopy(string $student = 'Curie Marie'): QuizAttempt
    {
        if (! $this->quiz->quizQuestions()->exists()) {
            $this->openQuestion();
        }

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => $student]);

        foreach ($this->quiz->quizQuestions()->get() as $question) {
            $this->post(route('quiz.answer', $this->quiz->token), [
                'question_id' => $question->id,
                'answer_text' => 'Huile et soude donnent du savon.',
            ]);
        }

        $this->post(route('quiz.submit', $this->quiz->token));

        return $this->quiz->attempts()->orderByDesc('id')->firstOrFail();
    }

    private function openAnswer(QuizAttempt $attempt): QuizAnswer
    {
        $attempt->load('answers.field');

        return $attempt->answers->first(fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true);
    }

    private function loginAsGrader(): void
    {
        $this->flushSession();

        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => $this->grader->email,
            'reference' => $this->grader->reference,
        ])->assertSessionHasNoErrors();
    }

    private function backToAdmin(): void
    {
        $this->flushSession();

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);
    }

    /** Le correcteur note la copie : c'est le point de départ de tout le fichier. */
    private function gradeAsGrader(QuizAttempt $attempt, string $points = '3', string $comment = 'Réponse complète.'): QuizAnswer
    {
        $answer = $this->openAnswer($attempt);

        $this->loginAsGrader();

        $this->post(route('correction.store', $attempt), [
            'points' => [$answer->id => $points],
            'comments' => [$answer->id => $comment],
        ])->assertSessionHasNoErrors();

        return $answer->refresh();
    }

    // ------------------------------------------------------ Motif obligatoire

    public function test_reprendre_la_note_d_un_correcteur_exige_un_motif(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'comments' => [$answer->id => 'Finalement insuffisant.'],
        ])->assertSessionHasErrors('review_reason.'.$answer->id);

        // Rien n'a bougé : ni la note, ni son auteur, ni le journal.
        $answer->refresh();

        $this->assertSame('3.00', $answer->points_awarded);
        $this->assertSame($this->grader->id, $answer->graded_by_grader_id);
        $this->assertSame(0, QuizGradeReview::count());
    }

    public function test_un_motif_trop_long_est_refuse(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'review_reason' => [$answer->id => str_repeat('a', 501)],
        ])->assertSessionHasErrors('review_reason.'.$answer->id);
    }

    public function test_modifier_sa_propre_note_ne_demande_aucun_motif(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        // L'administrateur note le premier, puis se ravise : il n'a de compte à
        // rendre à personne, mais le changement est journalisé.
        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '3'],
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
        ])->assertSessionHasNoErrors();

        $answer->refresh();

        $this->assertSame('2.00', $answer->points_awarded);
        $this->assertSame($this->admin->id, $answer->graded_by_admin_id);

        $review = QuizGradeReview::firstOrFail();

        $this->assertSame($this->admin->id, $review->reviewed_by_admin_id);
        $this->assertSame($this->admin->id, $review->previous_admin_id);
        $this->assertSame('3.00', $review->previous_points);
    }

    // ------------------------------------------------------------- La reprise

    public function test_la_reprise_archive_la_note_du_correcteur_et_change_d_auteur(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1.5'],
            'comments' => [$answer->id => 'Le calcul final est faux.'],
            'review_reason' => [$answer->id => 'Barème trop généreux sur cette question.'],
        ])->assertSessionHasNoErrors();

        $answer->refresh();

        // La note retenue est celle de l'administration, et elle en porte le nom.
        $this->assertSame('1.50', $answer->points_awarded);
        $this->assertSame('Le calcul final est faux.', $answer->grader_comment);
        $this->assertSame($this->admin->id, $answer->graded_by_admin_id);
        $this->assertNull($answer->graded_by_grader_id);
        $this->assertSame('admin (admin)', $answer->gradedByLabel());

        // La note du correcteur n'est pas perdue : elle est archivée.
        $review = QuizGradeReview::firstOrFail();

        $this->assertSame($answer->id, $review->quiz_answer_id);
        $this->assertSame('3.00', $review->previous_points);
        $this->assertSame('Réponse complète.', $review->previous_comment);
        $this->assertSame($this->grader->id, $review->previous_grader_id);
        $this->assertSame($this->admin->id, $review->reviewed_by_admin_id);
        $this->assertSame('Barème trop généreux sur cette question.', $review->reason);
        $this->assertSame('Awa Kouassi (correcteur)', $review->previousAuthorLabel());
    }

    public function test_la_note_de_la_copie_est_recalculee_apres_la_reprise(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->assertSame('3.00', $attempt->refresh()->score);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '0'],
            'review_reason' => [$answer->id => 'Réponse hors sujet.'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $attempt->refresh()->score);
    }

    public function test_une_note_confirmee_apres_examen_reste_au_nom_du_correcteur(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        // Même note, mais un motif : l'administration a relu et maintient.
        // La relecture est tracée, l'auteur ne change pas — c'est bien sa note.
        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '3'],
            'comments' => [$answer->id => 'Réponse complète.'],
            'review_reason' => [$answer->id => 'Note examinée : barème bien appliqué.'],
        ])->assertSessionHasNoErrors();

        $answer->refresh();

        $this->assertSame('3.00', $answer->points_awarded);
        $this->assertSame($this->grader->id, $answer->graded_by_grader_id);
        $this->assertNull($answer->graded_by_admin_id);

        $review = QuizGradeReview::firstOrFail();

        $this->assertSame('Note examinée : barème bien appliqué.', $review->reason);
        $this->assertSame($this->grader->id, $review->previous_grader_id);
    }

    public function test_enregistrer_exactement_la_meme_note_ne_cree_aucune_ligne_de_journal(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
            'comments' => [$answer->id => 'Correct.'],
        ])->assertSessionHasNoErrors();

        // Deuxième enregistrement à l'identique : un simple rechargement de la
        // page ne doit pas remplir le journal de faux changements.
        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '2'],
            'comments' => [$answer->id => 'Correct.'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, QuizGradeReview::count());
    }

    // -------------------------------------------------- Ce que le correcteur voit

    public function test_le_correcteur_ne_peut_plus_modifier_une_note_reprise(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'comments' => [$answer->id => 'Insuffisant.'],
            'review_reason' => [$answer->id => 'Réponse incomplète.'],
        ])->assertSessionHasNoErrors();

        // Le correcteur rejoue le formulaire avec sa note d'origine : la
        // décision de l'administration tient.
        $this->loginAsGrader();

        $this->post(route('correction.store', $attempt), [
            'points' => [$answer->id => '3'],
            'comments' => [$answer->id => 'Réponse complète.'],
        ])->assertSessionHasNoErrors();

        $answer->refresh();

        $this->assertSame('1.00', $answer->points_awarded);
        $this->assertSame('Insuffisant.', $answer->grader_comment);
        $this->assertSame($this->admin->id, $answer->graded_by_admin_id);

        // Une seule relecture au journal : sa tentative n'a rien produit.
        $this->assertSame(1, QuizGradeReview::count());
        $this->assertSame($this->admin->id, QuizGradeReview::firstOrFail()->reviewed_by_admin_id);
    }

    public function test_la_page_du_correcteur_montre_la_reprise_sans_champ_de_note(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'review_reason' => [$answer->id => 'Le barème a été mal appliqué.'],
        ])->assertSessionHasNoErrors();

        $this->loginAsGrader();

        $this->get(route('correction.show', $attempt))
            ->assertOk()
            ->assertSee("Note reprise par l'administration", false)
            ->assertSee('Le barème a été mal appliqué.')
            ->assertSee('1 / 3')
            // Aucun champ de note pour cette réponse : elle ne se modifie plus.
            ->assertDontSee('points['.$answer->id.']', false)
            ->assertDontSee('comments['.$answer->id.']', false);
    }

    public function test_le_correcteur_modifie_toujours_les_reponses_non_reprises(): void
    {
        $attempt = $this->playCopy();

        // Deux questions rédigées : une reprise par l'administration, une autre
        // que le correcteur doit pouvoir corriger normalement.
        $this->openQuestion();
        $attempt = $this->playCopy('Turing Alan');

        $attempt->load('answers.field');
        $open = $attempt->answers
            ->filter(fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true)
            ->sortBy(fn (QuizAnswer $answer): int => (int) $answer->field->order)
            ->values();

        $this->loginAsGrader();

        $this->post(route('correction.store', $attempt), [
            'points' => [$open[0]->id => '3', $open[1]->id => '3'],
        ])->assertSessionHasNoErrors();

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$open[0]->id => '1'],
            'review_reason' => [$open[0]->id => 'Réponse incomplète.'],
        ])->assertSessionHasNoErrors();

        $this->loginAsGrader();

        // Il retouche la seconde, laissée libre : elle doit s'enregistrer.
        $this->post(route('correction.store', $attempt), [
            'points' => [$open[1]->id => '2'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('1.00', $open[0]->refresh()->points_awarded);
        $this->assertSame('2.00', $open[1]->refresh()->points_awarded);
    }

    // ------------------------------------------------------- Ce que l'admin voit

    public function test_la_page_de_correction_montre_le_motif_et_la_note_precedente(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1.5'],
            'review_reason' => [$answer->id => 'Deux étapes justes, calcul final faux.'],
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('Journal de la note')
            ->assertSee('Deux étapes justes, calcul final faux.')
            ->assertSee('Note précédente : 3 / 3')
            ->assertSee('Awa Kouassi (correcteur)')
            ->assertSee('Note posée par admin (admin).');
    }

    public function test_une_note_a_reprendre_annonce_le_motif_obligatoire(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('Motif de la reprise')
            ->assertSee('obligatoire pour modifier cette note')
            ->assertSee('review_reason['.$answer->id.']', false);
    }

    public function test_les_resultats_signalent_les_copies_dont_une_note_a_ete_revue(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertDontSee('note(s) revue(s)');

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'review_reason' => [$answer->id => 'Barème.'],
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('1 note(s) revue(s)');
    }

    // ---------------------------------------------------------- Export et suivi

    public function test_l_export_du_correcteur_garde_sa_note_et_signale_la_reprise(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'comments' => [$answer->id => 'Insuffisant.'],
            'review_reason' => [$answer->id => 'Réponse incomplète.'],
        ])->assertSessionHasNoErrors();

        $this->loginAsGrader();

        $csv = $this->get(route('correction.export'))->streamedContent();

        // Sa ligne reste : c'est son travail. Sa note y figure telle qu'il l'a
        // posée, et la colonne « Révision » dit ce qui a été décidé à sa place.
        $this->assertStringContainsString($attempt->reference, $csv);
        $this->assertStringContainsString('Réponse complète.', $csv);
        $this->assertStringContainsString("Reprise par l'administration", $csv);
        $this->assertStringContainsString('Réponse incomplète.', $csv);
    }

    public function test_le_correcteur_est_prevenu_dans_son_espace(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'review_reason' => [$answer->id => 'Barème.'],
        ])->assertSessionHasNoErrors();

        $this->loginAsGrader();

        $this->get(route('correction.index'))
            ->assertOk()
            // La copie reste dans son historique, avec la reprise annoncée.
            ->assertSee($attempt->reference)
            ->assertSee("1 note(s) reprise(s) par l'administration", false);
    }

    public function test_l_administrateur_voit_le_nombre_de_notes_reprises_par_correcteur(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->gradeAsGrader($attempt);

        $this->backToAdmin();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '1'],
            'review_reason' => [$answer->id => 'Barème.'],
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.quizzes.graders', $this->quiz))
            ->assertOk()
            ->assertSee('1 note(s) revue(s) par vous');
    }
}

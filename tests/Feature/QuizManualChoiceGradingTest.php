<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizGradeReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Correction manuelle des QCM.
 *
 * Le réglage de l'évaluation décide : désactivé (défaut), une question à
 * propositions est notée à la remise, exactement comme avant. Activé, l'auto-
 * correction est retirée et l'enseignant note lui-même — la copie entre dans la
 * file de correction, au même titre qu'une réponse rédigée.
 *
 * Deux garanties tiennent tout ce fichier :
 *   1. le défaut ne change rien — les évaluations existantes gardent leur note
 *      automatique ;
 *   2. une fois le réglage actif, aucune note n'est inventée : la réponse attend,
 *      puis c'est l'enseignant qui tranche.
 */
class QuizManualChoiceGradingTest extends TestCase
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

        $this->quiz = $this->makeQuiz();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function makeQuiz(bool $manual = true): Form
    {
        return Form::create([
            'title' => 'Évaluation à correction manuelle',
            'token' => 'JETON-QCM-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
                'requires_reference' => false,
                'manual_choice_grading' => $manual,
            ],
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, int>  $correct
     */
    private function choiceQuestion(array $options = ['Un', 'Deux'], array $correct = [1], float $points = 2): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Quelle est la bonne réponse ?',
            'field_type' => count($correct) > 1 ? 'checkbox' : 'radio',
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => $options,
            'correct_answer' => $correct,
            'points' => $points,
        ]);
    }

    private function openQuestion(float $points = 3): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Expliquez votre raisonnement.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => 'Un raisonnement structuré.',
            'points' => $points,
        ]);
    }

    /**
     * Joue une copie entière par les routes réelles.
     *
     * `$choice` est l'index coché à chaque QCM, `$text` la réponse rédigée. On
     * joue une seule question à propositions sauf mention contraire.
     */
    private function playCopy(int $choice = 1, string $text = 'Voici mon raisonnement.'): QuizAttempt
    {
        if (! $this->quiz->quizQuestions()->exists()) {
            $this->choiceQuestion();
            $this->openQuestion();
        }

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Candidat Test']);

        foreach ($this->quiz->quizQuestions()->get() as $question) {
            $this->post(route('quiz.answer', $this->quiz->token), $question->isOpen()
                ? ['question_id' => $question->id, 'answer_text' => $text]
                : ['question_id' => $question->id, 'choice' => $choice]);
        }

        $this->post(route('quiz.submit', $this->quiz->token));

        return $this->quiz->attempts()->orderByDesc('id')->firstOrFail();
    }

    private function choiceAnswer(QuizAttempt $attempt): QuizAnswer
    {
        $attempt->load('answers.field');

        return $attempt->answers->first(fn (QuizAnswer $answer): bool => $answer->field?->isChoice() === true);
    }

    private function gradeRoute(QuizAttempt $attempt): string
    {
        return route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]);
    }

    // -------------------------------------------------------------- Non-régression

    public function test_le_defaut_reste_l_auto_correction(): void
    {
        $this->quiz = $this->makeQuiz(manual: false);

        $attempt = $this->playCopy(choice: 1);
        $answer = $this->choiceAnswer($attempt);

        // Bonne réponse cochée : la note automatique est posée, la réponse n'est
        // pas comptée comme à corriger. Seule la question rédigée reste en
        // attente — c'est le comportement historique, inchangé.
        $this->assertSame('2.00', $answer->points_awarded);
        $this->assertTrue($answer->is_correct);
        $this->assertSame(1, $attempt->pendingManualCount());
    }

    public function test_le_defaut_penalise_toujours_une_mauvaise_reponse(): void
    {
        $this->quiz = $this->makeQuiz(manual: false);

        $attempt = $this->playCopy(choice: 0);
        $answer = $this->choiceAnswer($attempt);

        $this->assertSame('0.00', $answer->points_awarded);
        $this->assertFalse($answer->is_correct);
        // Toujours une seule attente : la question rédigée.
        $this->assertSame(1, $attempt->pendingManualCount());
    }

    // ---------------------------------------------------------- Mode manuel

    public function test_le_mode_manuel_retire_la_note_automatique(): void
    {
        $attempt = $this->playCopy(choice: 1);
        $answer = $this->choiceAnswer($attempt);

        // Même avec la bonne réponse cochée, rien n'est noté : la décision revient
        // à l'enseignant. Les deux questions attendent donc une note.
        $this->assertNull($answer->points_awarded);
        $this->assertNull($answer->is_correct);
        $this->assertSame(2, $attempt->pendingManualCount());
        $this->assertTrue($attempt->awaitsManualGrading());
    }

    public function test_la_copie_manuelle_apparait_dans_la_file_de_correction(): void
    {
        $attempt = $this->playCopy();

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('1 copie(s) attendent une correction')
            // L'apostrophe est échappée dans le HTML : on cherche le fragment sans
            // elle, ce qui suffit à prouver que la phrase du mode manuel est là.
            ->assertSee('auto-correction des QCM est désactivée')
            ->assertSee('Corriger (2)')
            ->assertSee('provisoire')
            ->assertSee('Réponses à corriger (CSV)');

        // L'entrée de la série ouvre la première copie à corriger, avec le mode
        // « en série » : l'enseignant enchaîne sans repasser par la liste.
        $this->get(route('admin.quizzes.grade', $this->quiz))
            ->assertRedirect($this->gradeRoute($attempt).'?serie=1');
    }

    public function test_l_enseignant_note_un_qcm_manuel(): void
    {
        $attempt = $this->playCopy(choice: 1);
        $choice = $this->choiceAnswer($attempt);

        // Un demi-point accordé alors que la bonne réponse est cochée : la note
        // est celle de l'enseignant, pas celle de la machine.
        $this->post($this->gradeRoute($attempt), [
            'points' => [$choice->id => '1.5'],
            'comments' => [$choice->id => 'Raisonnement juste mais incomplet.'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $attempt->refresh();
        $choice->refresh();

        $this->assertSame('1.50', $choice->points_awarded);
        $this->assertFalse($choice->is_correct);
        $this->assertSame('Raisonnement juste mais incomplet.', $choice->grader_comment);
        $this->assertSame($this->admin->id, $choice->graded_by_admin_id);

        // La note rédigée reste en attente : la copie n'est pas encore close.
        $this->assertSame(1, $attempt->pendingManualCount());
    }

    public function test_une_note_manuelle_de_qcm_peut_etre_reprise(): void
    {
        $attempt = $this->playCopy();
        $choice = $this->choiceAnswer($attempt);

        $this->post($this->gradeRoute($attempt), ['points' => [$choice->id => '1']]);

        $choice->refresh();
        $this->assertSame('1.00', $choice->points_awarded);

        // Le formulaire renvoie la valeur complète : la reprendre laisse une
        // trace au journal, comme pour une réponse rédigée.
        $this->post($this->gradeRoute($attempt), ['points' => [$choice->id => '2']]);

        $choice->refresh();
        $this->assertSame('2.00', $choice->points_awarded);
        $this->assertSame(1, QuizGradeReview::where('quiz_answer_id', $choice->id)->count());
    }

    public function test_l_etudiant_voit_le_qcm_manuel_en_attente_puis_note(): void
    {
        $attempt = $this->playCopy();
        $choice = $this->choiceAnswer($attempt);

        // Le détail n'est publié qu'une fois l'évaluation fermée : on la ferme
        // après la copie pour pouvoir lire ce que voit l'étudiant.
        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('En attente de correction')
            ->assertDontSee('Juste — 2 point(s)');

        $this->post($this->gradeRoute($attempt), ['points' => [$choice->id => '2']]);

        $choice->refresh();
        $this->assertSame('2.00', $choice->points_awarded);

        // Une fois noté, le QCM affiche sa note — la réponse rédigée, elle, reste
        // annoncée en attente.
        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('2 / 2.00 pt')
            ->assertSee('En attente de correction');
    }

    public function test_le_guide_indique_la_bonne_reponse_sans_l_appliquer(): void
    {
        $attempt = $this->playCopy(choice: 0);
        $choice = $this->choiceAnswer($attempt);

        // La bonne réponse est montrée à l'enseignant, mais elle n'a pas été
        // appliquée : le champ de note est vide et attend sa décision.
        $this->get($this->gradeRoute($attempt))
            ->assertOk()
            ->assertSee('Bonne réponse (indicative)')
            ->assertSee('Deux')
            ->assertSee('points['.$choice->id.']', false);
    }

    public function test_l_export_inclut_les_qcm_manuels(): void
    {
        $this->playCopy(choice: 0);

        $response = $this->get(route('admin.quizzes.results.open-answers', $this->quiz));

        $response->assertOk();
        $this->assertStringContainsString('Un', $response->streamedContent());
        $this->assertStringContainsString('À corriger', $response->streamedContent());
    }

    public function test_l_export_ne_change_pas_en_mode_automatique(): void
    {
        $this->quiz = $this->makeQuiz(manual: false);

        $this->playCopy(choice: 1);

        // En mode automatique, l'export ne contient que les réponses rédigées :
        // la question à propositions n'y figure pas.
        $response = $this->get(route('admin.quizzes.results.open-answers', $this->quiz));

        $response->assertOk();
        $this->assertStringContainsString('Voici mon raisonnement.', $response->streamedContent());
        $this->assertStringNotContainsString('Quelle est la bonne réponse ?', $response->streamedContent());
    }
}

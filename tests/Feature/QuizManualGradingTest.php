<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Correction manuelle des réponses rédigées.
 *
 * Ce fichier tient une idée simple : une copie rendue avec des questions
 * ouvertes n'a pas de note définitive tant que l'enseignant n'a pas tranché. La
 * note affichée est provisoire, elle devient définitive après la correction, et
 * l'étudiant la voit changer dans son récapitulatif sans avoir à se reconnecter.
 */
class QuizManualGradingTest extends TestCase
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

        $this->quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-CORRECTION',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => true],
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    /**
     * @param  array<int, string>  $options
     * @param  array<int, int>  $correct
     */
    private function question(array $options = ['Un', 'Deux'], array $correct = [1], float $points = 2): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Question à propositions',
            'field_type' => 'radio',
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => $options,
            'correct_answer' => $correct,
            'points' => $points,
        ]);
    }

    private function openQuestion(float $points = 3, ?string $expected = 'Huile et soude donnent du savon et de la glycérine.'): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Expliquez la saponification en deux ou trois lignes.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => $expected,
            'points' => $points,
        ]);
    }

    /**
     * Joue une copie entière par les routes réelles : c'est le seul moyen d'être
     * sûr que l'état corrigé est bien celui d'une copie rendue par un étudiant.
     */
    private function playCopy(string $text = 'Huile et soude donnent du savon.'): QuizAttempt
    {
        $choice = $this->question();
        $open = $this->openQuestion();

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Curie Marie']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $choice->id, 'choice' => 1]);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $open->id, 'answer_text' => $text]);
        $this->post(route('quiz.submit', $this->quiz->token));

        return $this->quiz->attempts()->firstOrFail();
    }

    private function openAnswer(QuizAttempt $attempt): QuizAnswer
    {
        $attempt->load('answers.field');

        return $attempt->answers->first(fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true);
    }

    private function gradeRoute(QuizAttempt $attempt): string
    {
        return route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]);
    }

    // ---------------------------------------------------------------- Affichage

    public function test_les_resultats_signalent_les_copies_a_corriger(): void
    {
        $attempt = $this->playCopy();

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('1 copie(s) attendent une correction')
            ->assertSee('Corriger (1)')
            ->assertSee('provisoire')
            ->assertSee('Réponses rédigées (CSV)');
    }

    public function test_la_page_de_correction_montre_la_reponse_et_le_guide(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->get($this->gradeRoute($attempt))
            ->assertOk()
            ->assertSee('Huile et soude donnent du savon.')
            ->assertSee('Huile et soude donnent du savon et de la glycérine.')
            ->assertSee('Corriger la copie')
            ->assertSee('points['.$answer->id.']', false)
            ->assertSee('provisoire');
    }

    // -------------------------------------------------------------- Correction

    public function test_la_correction_remplace_la_note_provisoire(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->assertSame('2.00', $attempt->score);
        $this->assertSame(1, $attempt->pendingManualCount());

        $this->post($this->gradeRoute($attempt), [
            'points' => [$answer->id => '3'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $attempt->refresh();
        $answer->refresh();

        $this->assertSame('5.00', $attempt->score);
        $this->assertSame('5.00', $attempt->max_score);
        $this->assertSame('3.00', $answer->points_awarded);
        $this->assertTrue($answer->is_correct);
        $this->assertSame(0, $attempt->pendingManualCount());
        $this->assertFalse($attempt->awaitsManualGrading());

        // La note n'est plus annoncée comme provisoire, à l'écran comme dans le PDF.
        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertDontSee('Note provisoire');

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertDontSee('copie(s) attendent une correction');
    }

    public function test_une_note_partielle_est_acceptee_et_n_est_pas_une_reponse_juste(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post($this->gradeRoute($attempt), [
            'points' => [$answer->id => '1.5'],
        ])->assertRedirect();

        $answer->refresh();
        $attempt->refresh();

        // Une correction humaine peut accorder la moitié : c'est tout l'intérêt.
        $this->assertSame('1.50', $answer->points_awarded);
        $this->assertFalse($answer->is_correct);
        $this->assertSame('3.50', $attempt->score);
        $this->assertSame(0, $attempt->pendingManualCount());
    }

    public function test_une_note_superieure_au_bareme_est_refusee(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post($this->gradeRoute($attempt), [
            'points' => [$answer->id => '4'],
        ])->assertSessionHasErrors('points.'.$answer->id);

        $answer->refresh();
        $attempt->refresh();

        // Rien n'a été écrit : une copie ne peut pas dépasser son barème.
        $this->assertNull($answer->points_awarded);
        $this->assertSame('2.00', $attempt->score);
        $this->assertSame(1, $attempt->pendingManualCount());
    }

    public function test_une_note_negative_est_refusee(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post($this->gradeRoute($attempt), [
            'points' => [$answer->id => '-1'],
        ])->assertSessionHasErrors('points.'.$answer->id);

        $this->assertNull($answer->refresh()->points_awarded);
    }

    public function test_un_champ_laisse_vide_garde_la_reponse_en_attente(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post($this->gradeRoute($attempt), [
            'points' => [$answer->id => ''],
        ])->assertRedirect();

        $this->assertNull($answer->refresh()->points_awarded);
        $this->assertSame(1, $attempt->refresh()->pendingManualCount());
    }

    public function test_la_correction_est_rejouable(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post($this->gradeRoute($attempt), ['points' => [$answer->id => '3']]);
        $this->assertSame('5.00', $attempt->refresh()->score);

        // Un enseignant qui se ravise ne doit pas être prisonnier de sa première
        // note : la copie reste corrigeable.
        $this->post($this->gradeRoute($attempt), ['points' => [$answer->id => '2']]);

        $this->assertSame('4.00', $attempt->refresh()->score);
        $this->assertSame('2.00', $answer->refresh()->points_awarded);
    }

    public function test_on_ne_note_que_les_reponses_de_cette_copie(): void
    {
        $first = $this->playCopy('Première copie.');
        $firstAnswer = $this->openAnswer($first);

        // Deuxième candidat, sur la même évaluation : sa copie est écrite
        // directement en base, parce que ce qui est testé ici est la garde du
        // contrôleur, pas le parcours étudiant (couvert ailleurs).
        $second = $this->quiz->attempts()->create([
            'reference' => 'REFDEUXIEME',
            'student_name' => 'Turing Alan',
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        $secondAnswer = $second->answers()->create([
            'form_field_id' => $this->quiz->quizQuestions()->where('field_type', FormField::OPEN_TYPE)->value('id'),
            'answer_text' => 'Deuxième copie.',
            'answered_at' => Carbon::now(),
        ]);

        // L'identifiant de la réponse de l'autre candidat est glissé dans le
        // formulaire : il doit être ignoré.
        $this->post($this->gradeRoute($first), [
            'points' => [
                $firstAnswer->id => '3',
                $secondAnswer->id => '3',
            ],
        ])->assertRedirect();

        $this->assertSame('3.00', $firstAnswer->refresh()->points_awarded);
        $this->assertNull($secondAnswer->refresh()->points_awarded);
        $this->assertSame(1, $second->refresh()->pendingManualCount());
    }

    public function test_une_copie_non_rendue_ne_se_corrige_pas(): void
    {
        $question = $this->question();
        $open = $this->openQuestion();

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Curie Marie']);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.answer', $this->quiz->token), ['question_id' => $open->id, 'answer_text' => 'En cours.']);

        $attempt = $this->quiz->attempts()->firstOrFail();

        $this->assertTrue($attempt->isInProgress());

        $this->get($this->gradeRoute($attempt))
            ->assertRedirect(route('admin.quizzes.results', $this->quiz))
            ->assertSessionHas('error');

        $this->post($this->gradeRoute($attempt), ['points' => [$open->id => '3']])
            ->assertRedirect(route('admin.quizzes.results', $this->quiz));

        $this->assertNull($this->openAnswer($attempt)->refresh()->points_awarded);
    }

    public function test_la_correction_d_une_autre_evaluation_est_refusee(): void
    {
        $attempt = $this->playCopy();

        $other = Form::create([
            'title' => 'Autre examen',
            'token' => 'AUTRE-JETON',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['duration_minutes' => 30],
            'created_by' => $this->admin->id,
        ]);

        $this->get(route('admin.quizzes.attempts.grade', [$other, $attempt]))->assertNotFound();
        $this->post(route('admin.quizzes.attempts.grade', [$other, $attempt]), ['points' => []])->assertNotFound();
    }

    // ------------------------------------------------------------------ Exports

    public function test_l_export_des_reponses_redigees_contient_les_textes(): void
    {
        $attempt = $this->playCopy('Huile et soude donnent du savon.');

        $response = $this->get(route('admin.quizzes.results.open-answers', $this->quiz));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Référence;Nom;Question;Réponse;Points;Barème;Correction', $csv);
        $this->assertStringContainsString('Expliquez la saponification en deux ou trois lignes.', $csv);
        $this->assertStringContainsString('Huile et soude donnent du savon.', $csv);
        $this->assertStringContainsString($attempt->reference, $csv);
        $this->assertStringContainsString('À corriger', $csv);
    }

    public function test_l_export_des_reponses_redigees_marque_une_copie_corrigee(): void
    {
        $attempt = $this->playCopy();
        $answer = $this->openAnswer($attempt);

        $this->post($this->gradeRoute($attempt), ['points' => [$answer->id => '3']]);

        $csv = $this->get(route('admin.quizzes.results.open-answers', $this->quiz))->streamedContent();

        $this->assertStringContainsString('Corrigée', $csv);
        $this->assertStringNotContainsString('À corriger', $csv);
    }

    public function test_l_export_des_reponses_redigees_respecte_l_anonymat(): void
    {
        $this->quiz->update(['is_anonymous' => true]);

        $this->playCopy('Copie anonyme.');

        $csv = $this->get(route('admin.quizzes.results.open-answers', $this->quiz))->streamedContent();

        $this->assertStringContainsString('Copie anonyme.', $csv);
        $this->assertStringNotContainsString('Curie Marie', $csv);
    }

    public function test_l_export_des_resultats_compte_les_reponses_a_corriger(): void
    {
        $attempt = $this->playCopy();

        // La copie a été commencée dix minutes avant d'être rendue.
        $attempt->update(['started_at' => Carbon::now()->subMinutes(10)]);

        $csv = $this->get(route('admin.quizzes.results.export', $this->quiz))->streamedContent();

        $this->assertStringContainsString('"Réponses libres à corriger"', $csv);

        // Lecture de la ligne de la copie : note des QCM, barème total, temps,
        // puis le nombre de réponses libres encore à corriger.
        foreach (explode("\n", $csv) as $line) {
            if (str_contains($line, $attempt->reference)) {
                $this->assertStringContainsString(';2,00;5,00;10;1;0;', $line);

                return;
            }
        }

        $this->fail('La ligne de la participation est absente de l\'export.');
    }
}

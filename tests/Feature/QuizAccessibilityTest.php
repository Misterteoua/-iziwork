<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Garde-fous d'accessibilité du parcours étudiant.
 *
 * Les deux règles tenues ici viennent d'un audit automatisé (axe-core) mené sur
 * les cinq écrans du parcours — salle d'attente, page d'accès, question,
 * confirmation, résultat :
 *
 *   - chaque écran porte un titre de niveau 1, et un seul. Sans lui, la page
 *     n'a aucun titre : un lecteur d'écran n'annonce pas où mène le lien, et la
 *     hiérarchie des titres ne commence nulle part ;
 *   - aucun texte du parcours n'emprunte la couleur la plus claire de la palette
 *     (slate-400), qui ne tenait que 2,45:1 sur le fond gris de la page et
 *     2,56:1 sur blanc, loin des 4,5:1 demandés à un texte courant.
 *
 * Le test lit le HTML réellement rendu : c'est ce que voit l'étudiant, et non
 * l'intention du gabarit.
 */
class QuizAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));

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

    public function test_la_salle_d_attente_et_la_page_d_acces_sont_lisibles(): void
    {
        // Salle d'attente : l'épreuve n'a pas encore ouvert. C'est le seul écran
        // du parcours où l'étudiant attend, et il porte le décompte.
        $waiting = $this->makeQuiz([
            'open_date' => Carbon::now()->addHour(),
            'close_date' => Carbon::now()->addHours(3),
        ]);

        $this->assertReadable(
            $this->get(route('quiz.start', $waiting->token))->assertOk(),
            'salle d\'attente'
        );

        // Page d'accès : l'épreuve est ouverte, le formulaire s'affiche.
        $this->assertReadable(
            $this->get(route('quiz.start', $this->quiz->token))->assertOk(),
            'page d\'accès'
        );
    }

    public function test_les_ecrans_de_l_epreuve_et_du_resultat_sont_lisibles(): void
    {
        $choice = $this->question();
        $open = $this->question(FormField::OPEN_TYPE);

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Awa Ndiaye'])
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        $this->assertReadable(
            $this->get(route('quiz.question', $this->quiz->token))->assertOk(),
            'question à propositions'
        );

        // La question rédigée est celle qui affiche la mention « facultatif » —
        // le texte le plus clair de tout le parcours, et celui qui se perdait.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $choice->id,
            'choice' => 1,
        ]);

        $this->assertReadable(
            $this->get(route('quiz.question', $this->quiz->token))->assertOk(),
            'question rédigée'
        );

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $open->id,
            'answer_text' => 'Huile et soude donnent du savon.',
        ]);

        $this->assertReadable(
            $this->get(route('quiz.submit.page', $this->quiz->token))->assertOk(),
            'confirmation'
        );

        $this->post(route('quiz.submit', $this->quiz->token))
            ->assertRedirect(route('quiz.result', $this->quiz->token));

        $this->assertReadable(
            $this->get(route('quiz.result', $this->quiz->token))->assertOk(),
            'résultat'
        );
    }

    // ------------------------------------------------------------- Utilitaires

    /**
     * Un écran du parcours : un seul titre de niveau 1, et aucun texte dans la
     * couleur la plus claire de la palette.
     */
    private function assertReadable(TestResponse $response, string $screen): void
    {
        $html = (string) $response->getContent();

        $this->assertSame(
            1,
            substr_count($html, '<h1'),
            "L'écran « {$screen} » doit porter exactement un titre de niveau 1."
        );

        $this->assertStringNotContainsString(
            'text-slate-400',
            $html,
            "L'écran « {$screen} » utilise la couleur de texte la plus claire (contraste insuffisant)."
        );
    }

    private function makeQuiz(array $attributes = []): Form
    {
        return Form::create(array_merge([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-A11Y-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => true],
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function question(string $type = 'radio'): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Question '.uniqid(),
            'field_type' => $type,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => $type === FormField::OPEN_TYPE ? null : ['Un', 'Deux', 'Trois'],
            'correct_answer' => $type === FormField::OPEN_TYPE ? null : [1],
            'points' => 1,
        ]);
    }
}

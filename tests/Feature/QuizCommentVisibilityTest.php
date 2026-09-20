<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ce que l'étudiant lit de l'appréciation laissée sur sa copie.
 *
 * La règle tenue ici est celle qui a été choisie : le commentaire n'apparaît
 * qu'une fois **toutes** les rédactions notées. Sur une note provisoire, il
 * serait lu comme définitif — et un correcteur qui écrit une remarque avant
 * d'avoir fini de corriger se verrait imposer des mots qu'il aurait nuancés.
 */
class QuizCommentVisibilityTest extends TestCase
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
            'token' => 'JETON-COMMENTAIRES',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => true,
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

    private function openQuestion(int $order, string $label, float $points = 3): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => $label,
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => $order,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => 'Huile et soude donnent du savon.',
            'points' => $points,
        ]);
    }

    /**
     * Une copie avec deux questions rédigées : c'est le cas qui distingue
     * « corrigée » de « entièrement corrigée ».
     */
    private function playCopy(): QuizAttempt
    {
        if (! $this->quiz->quizQuestions()->exists()) {
            $this->openQuestion(1, 'Première question rédigée.');
            $this->openQuestion(2, 'Seconde question rédigée.');
        }

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Curie Marie']);

        foreach ($this->quiz->quizQuestions()->get() as $question) {
            $this->post(route('quiz.answer', $this->quiz->token), [
                'question_id' => $question->id,
                'answer_text' => 'Huile et soude donnent du savon.',
            ]);
        }

        $this->post(route('quiz.submit', $this->quiz->token));

        return $this->quiz->attempts()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @return \Illuminate\Support\Collection<int, QuizAnswer>
     */
    private function openAnswers(QuizAttempt $attempt)
    {
        $attempt->load('answers.field');

        return $attempt->answers
            ->filter(fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true)
            ->sortBy(fn (QuizAnswer $answer): int => (int) $answer->field->order)
            ->values();
    }

    /**
     * La page de résultat telle que l'étudiant la retrouve : par son lien de
     * suivi, sans session de navigateur. C'est aussi ce qui vérifie que la
     * règle d'affichage tient sur les deux chemins, puisqu'ils partagent la même
     * vue.
     */
    private function studentResult(QuizAttempt $attempt): string
    {
        return route('short.follow', ShortLink::forAttempt($this->quiz, $attempt)->code);
    }

    private function grade(QuizAttempt $attempt, QuizAnswer $answer, string $comment): void
    {
        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => '3'],
            'comments' => [$answer->id => $comment],
        ])->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------------- Web

    public function test_le_commentaire_reste_cache_tant_qu_une_reponse_attend(): void
    {
        $attempt = $this->playCopy();
        $answers = $this->openAnswers($attempt);

        // Une seule des deux questions est corrigée : la copie n'est pas prête.
        $this->grade($attempt, $answers[0], 'Première réponse complète.');

        $this->flushSession();

        $this->get($this->studentResult($attempt))
            ->assertOk()
            ->assertSee('En attente de correction')
            ->assertDontSee('Première réponse complète.');
    }

    public function test_le_commentaire_apparait_une_fois_la_copie_entierement_corrigee(): void
    {
        $attempt = $this->playCopy();
        $answers = $this->openAnswers($attempt);

        $this->grade($attempt, $answers[0], 'Première réponse complète.');
        $this->grade($attempt, $answers[1], 'La seconde manque de précision.');

        $this->flushSession();

        $this->get($this->studentResult($attempt))
            ->assertOk()
            ->assertSee('Appréciation')
            ->assertSee('Première réponse complète.')
            ->assertSee('La seconde manque de précision.')
            // Le correcteur n'est pas nommé à l'étudiant.
            ->assertDontSee('Awa Kouassi');
    }

    public function test_un_commentaire_vide_ne_produit_pas_de_cadre_vide(): void
    {
        $attempt = $this->playCopy();
        $answers = $this->openAnswers($attempt);

        $this->grade($attempt, $answers[0], 'Une appréciation.');
        $this->grade($attempt, $answers[1], '   ');

        $this->flushSession();

        $response = $this->get($this->studentResult($attempt))->assertOk()->assertSee('Une appréciation.');

        // Un seul cadre : une zone blanche laissée par le correcteur ne doit pas
        // produire un encadré vide, mais l'appréciation réelle reste affichée.
        $this->assertSame(1, substr_count($response->getContent(), 'Appréciation</p>'));
    }

    // -------------------------------------------------------------- PDF

    public function test_le_recapitulatif_pdf_suit_la_meme_regle(): void
    {
        $attempt = $this->playCopy();
        $answers = $this->openAnswers($attempt);
        $link = \App\Models\ShortLink::forAttempt($this->quiz, $attempt);

        $provisoire = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'pending' => 1,
            'followLink' => $link,
            'followQr' => QrPng::dataUri($link->url()),
        ])->render();

        $answers[0]->update(['points_awarded' => 3, 'grader_comment' => 'Première réponse complète.']);

        $withComment = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt->refresh(),
            'showScore' => true,
            'pending' => 1,
            'followLink' => $link,
            'followQr' => QrPng::dataUri($link->url()),
        ])->render();

        // Tant qu'une réponse attend, le commentaire ne figure pas au document.
        $this->assertStringNotContainsString('Première réponse complète.', $provisoire);
        $this->assertStringNotContainsString('Première réponse complète.', $withComment);

        $definitif = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt->refresh(),
            'showScore' => true,
            'pending' => 0,
            'followLink' => $link,
            'followQr' => QrPng::dataUri($link->url()),
        ])->render();

        $this->assertStringContainsString('Première réponse complète.', $definitif);
    }

    // -------------------------------------------------------- Traçabilité

    public function test_la_note_de_l_administrateur_porte_son_nom(): void
    {
        $attempt = $this->playCopy();
        $answers = $this->openAnswers($attempt);

        $this->grade($attempt, $answers[0], 'Corrigé par l’administration.');

        $answers[0]->refresh();

        $this->assertNull($answers[0]->graded_by_grader_id);
        $this->assertSame($this->admin->id, $answers[0]->graded_by_admin_id);
        $this->assertSame('admin (admin)', $answers[0]->gradedByLabel());
    }

    public function test_la_page_de_correction_montre_qui_a_pose_la_note(): void
    {
        $attempt = $this->playCopy();
        $answers = $this->openAnswers($attempt);

        // Le correcteur note la première question…
        $this->flushSession();
        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => $this->grader->email,
            'reference' => $this->grader->reference,
        ]);

        $this->post(route('correction.store', $attempt), [
            'points' => [$answers[0]->id => '3'],
        ])->assertSessionHasNoErrors();

        // …puis l'administrateur ouvre la même copie : il doit lire qui a noté.
        $this->flushSession();
        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('Note posée par Awa Kouassi (correcteur)');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tirage aléatoire et mélange des propositions.
 *
 * Ce qui est vérifié ici n'est pas « le hasard fonctionne » mais les garanties
 * qui rendent l'aléatoire utilisable en examen : le tirage est figé au démarrage,
 * la correction ne dépend pas de l'ordre affiché, et un candidat qui recharge sa
 * page retrouve exactement son épreuve.
 */
class QuizRandomisationTest extends TestCase
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

        $this->quiz = $this->makeQuiz();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function makeQuiz(array $settings = []): Form
    {
        return Form::create([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-ALEATOIRE-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => array_merge([
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => true,
                'draw_count' => null,
                'shuffle_questions' => false,
                'shuffle_options' => false,
            ], $settings),
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, int>  $correct
     */
    private function question(array $options = ['Un', 'Deux', 'Trois'], array $correct = [1], float $points = 1): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Question '.++$this->counter,
            'field_type' => 'radio',
            'required' => true,
            'order' => $this->counter,
            'options' => $options,
            'correct_answer' => $correct,
            'points' => $points,
        ]);
    }

    private function attempt(): QuizAttempt
    {
        return $this->quiz->attempts()->create(['reference' => $this->reference()]);
    }

    /**
     * Référence valide : dix caractères pris dans l'alphabet réel, qui exclut
     * 0, 1, I, L et O — une référence de test doit être acceptée par
     * `QuizReference::normalize()`, sinon les tests vérifieraient autre chose.
     */
    private function reference(): string
    {
        $digits = strtr((string) ++$this->counter, ['0' => '2', '1' => '3']);

        return 'REF'.str_pad($digits, 7, '4', STR_PAD_LEFT);
    }

    private function start(QuizAttempt $attempt): void
    {
        $this->post(route('quiz.begin', $this->quiz->token), [
            'reference' => $attempt->reference,
            'student_name' => 'Jean Dupont',
        ])->assertRedirect(route('quiz.question', $this->quiz->token));
    }

    // -------------------------------------------------------- Sans réglage

    public function test_sans_reglage_l_epreuve_reste_celle_de_l_administrateur(): void
    {
        $first = $this->question();
        $this->question();
        $this->question();

        $attempt = $this->attempt();
        $this->start($attempt);

        $attempt->refresh();

        // Ordre naturel enregistré explicitement : le plan est écrit même quand
        // aucun tirage n'est demandé, ce qui rend la copie relisible telle quelle.
        $this->assertSame(
            $this->quiz->quizQuestions()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $attempt->question_order
        );
        $this->assertNull($attempt->option_order);

        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertSee($first->field_label);

        // Et les propositions sont dans l'ordre d'origine.
        $this->assertSame(
            ['Un', 'Deux', 'Trois'],
            array_column($attempt->displayOptions($first), 'label')
        );
    }

    // ----------------------------------------------------------------- Tirage

    public function test_le_tirage_ne_pose_qu_une_partie_des_questions(): void
    {
        $this->quiz = $this->makeQuiz(['draw_count' => 2]);
        collect(range(1, 5))->each(fn () => $this->question(['Un', 'Deux'], [0], 2));

        $attempt = $this->attempt();
        $this->start($attempt);

        $attempt->refresh();
        $posées = $attempt->questions();

        $this->assertCount(2, $posées);
        $this->assertCount(2, $attempt->question_order);

        // Le barème suit les questions réellement posées, pas la banque entière.
        $this->assertSame('4.00', $attempt->max_score);

        // Les deux questions posées viennent bien de la banque.
        $banque = $this->quiz->quizQuestions()->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($attempt->question_order as $id) {
            $this->assertTrue($banque->contains($id));
        }

        foreach ($posées as $question) {
            $this->post(route('quiz.answer', $this->quiz->token), [
                'question_id' => $question->id,
                'choice' => 0,
            ]);
        }

        // Après la deuxième, l'épreuve est terminée : plus de question à poser.
        $this->get(route('quiz.question', $this->quiz->token))
            ->assertRedirect(route('quiz.submit.page', $this->quiz->token));

        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt->refresh();

        $this->assertSame('4.00', $attempt->score);
        $this->assertSame('4.00', $attempt->max_score);
    }

    public function test_le_tirage_est_fige_au_demarrage(): void
    {
        $this->quiz = $this->makeQuiz(['draw_count' => 3]);
        collect(range(1, 4))->each(fn () => $this->question(['Un', 'Deux'], [0]));

        $attempt = $this->attempt();
        $this->start($attempt);

        $attempt->refresh();
        $plan = $attempt->question_order;

        // Une question ajoutée pendant l'épreuve, puis une page rechargée.
        $ajoutée = $this->question(['Un', 'Deux'], [1]);

        $this->get(route('quiz.question', $this->quiz->token))->assertOk();

        $attempt->refresh();

        $this->assertSame($plan, $attempt->question_order);
        $this->assertNotContains((int) $ajoutée->id, $plan);
        $this->assertCount(3, $attempt->questions());
    }

    public function test_le_melange_des_questions_donne_des_ordres_differents(): void
    {
        $this->quiz = $this->makeQuiz(['shuffle_questions' => true]);
        collect(range(1, 5))->each(fn () => $this->question(['Un', 'Deux'], [0]));

        $ordres = [];

        foreach (range(1, 6) as $ignored) {
            $attempt = $this->attempt();
            // Chaque candidat arrive sur un navigateur vierge : sans cela, la
            // session reprendrait la participation du précédent.
            $this->flushSession();
            $this->start($attempt);
            $ordres[] = implode('-', $attempt->refresh()->question_order);
        }

        // Six tirages sur cinq questions : tous identiques serait le signe que le
        // mélange ne s'applique pas du tout.
        $this->assertGreaterThan(1, count(array_unique($ordres)));

        // Chaque tirage reste une permutation complète des questions.
        foreach ($ordres as $ordre) {
            $this->assertCount(5, explode('-', $ordre));
        }
    }

    // ------------------------------------------------------------ Propositions

    public function test_le_melange_conserve_l_index_d_origine_et_la_note(): void
    {
        $this->quiz = $this->makeQuiz(['shuffle_options' => true]);
        $question = $this->question(['Un', 'Deux', 'Trois', 'Quatre'], [2], 3);

        $attempt = $this->attempt();
        $this->start($attempt);

        $attempt->refresh();
        $display = $attempt->displayOptions($question);
        $originaux = array_column($display, 'original');

        // L'ordre affiché est une permutation des propositions…
        $sorted = $originaux;
        sort($sorted);
        $this->assertSame([0, 1, 2, 3], $sorted);

        // …et le formulaire envoie l'index d'origine de chaque proposition.
        $page = $this->get(route('quiz.question', $this->quiz->token))->assertOk();
        foreach ($display as $option) {
            $page->assertSee('value="'.$option['original'].'"', false);
        }

        // Le candidat répond juste : la note ne dépend pas de l'ordre reçu.
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 2,
        ])->assertRedirect(route('quiz.question', $this->quiz->token));

        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt->refresh();

        $this->assertSame('3.00', $attempt->score);
        $this->assertSame('3.00', $attempt->max_score);
    }

    public function test_un_index_hors_des_propositions_est_toujours_refuse(): void
    {
        $this->quiz = $this->makeQuiz(['shuffle_options' => true]);
        $question = $this->question(['Un', 'Deux'], [0]);

        $attempt = $this->attempt();
        $this->start($attempt);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 9,
        ])->assertSessionHasErrors('choice');

        $this->assertSame(0, $attempt->refresh()->answers()->count());
    }

    public function test_la_correction_affichee_respecte_l_ordre_du_candidat(): void
    {
        $this->quiz = $this->makeQuiz(['shuffle_options' => true]);
        $question = $this->question(['Alpha', 'Beta', 'Gamma', 'Delta'], [3], 2);

        $attempt = $this->attempt();
        $this->start($attempt);

        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'choice' => 3,
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt->refresh();

        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('Delta')
            ->assertSee('votre réponse');

        // Le récapitulatif PDF se rend aussi avec un mélange : la vue y désigne
        // les réponses par leur intitulé, pas par leur lettre.
        $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_un_ordre_de_propositions_incoherent_est_ignore(): void
    {
        $question = $this->question(['Un', 'Deux', 'Trois'], [1]);

        $attempt = $this->attempt();
        $this->start($attempt);

        // Colonne corrompue : il manque une proposition et une autre est en double.
        $attempt->forceFill(['option_order' => [(string) $question->id => [0, 0]]])->save();

        $this->assertSame(
            [0, 1, 2],
            array_column($attempt->refresh()->displayOptions($question), 'original')
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * L'espace d'un correcteur externe.
 *
 * Trois idées y sont tenues, et chacune a son test :
 *   1. le lien seul n'ouvre rien — il faut l'email ET la référence ;
 *   2. un correcteur ne voit que les évaluations qui lui sont affectées, et
 *      n'atteint jamais une page d'administration ;
 *   3. son export ne contient que ses propres notes et commentaires.
 */
class GraderCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    private Form $autreQuiz;

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

        $this->quiz = $this->makeQuiz('Examen de Chimie', 'JETON-CHIMIE');
        $this->autreQuiz = $this->makeQuiz('Examen de Physique', 'JETON-PHYSIQUE');

        $this->grader = Grader::createFor('Awa Kouassi', 'awa@test.com', Carbon::now()->addDays(3), $this->admin->id);
        $this->grader->forms()->attach($this->quiz->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function makeQuiz(string $title, string $token): Form
    {
        return Form::create([
            'title' => $title,
            'token' => $token,
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
    }

    private function openQuestion(Form $quiz, float $points = 3): FormField
    {
        return $quiz->fields()->create([
            'field_label' => 'Expliquez la saponification en deux ou trois lignes.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => (int) $quiz->fields()->max('order') + 1,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => 'Huile et soude donnent du savon et de la glycérine.',
            'points' => $points,
        ]);
    }

    /**
     * Joue une copie par les routes réelles : une copie fabriquée à la main
     * pourrait avoir un état que le parcours étudiant ne produit jamais.
     */
    private function playCopy(Form $quiz, string $student = 'Curie Marie', string $text = 'Huile et soude donnent du savon.'): QuizAttempt
    {
        if (! $quiz->quizQuestions()->exists()) {
            $this->openQuestion($quiz);
        }

        $this->post(route('quiz.begin', $quiz->token), ['student_name' => $student]);

        foreach ($quiz->quizQuestions()->get() as $question) {
            $this->post(route('quiz.answer', $quiz->token), [
                'question_id' => $question->id,
                'answer_text' => $text,
            ]);
        }

        $this->post(route('quiz.submit', $quiz->token));

        return $quiz->attempts()->orderByDesc('id')->firstOrFail();
    }

    private function openAnswer(QuizAttempt $attempt): QuizAnswer
    {
        $attempt->load('answers.field');

        return $attempt->answers->first(fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true);
    }

    private function login(): void
    {
        $this->flushSession();

        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => $this->grader->email,
            'reference' => $this->grader->reference,
        ])->assertRedirect(route('correction.index'))->assertSessionHasNoErrors();
    }

    // ----------------------------------------------------------------- Accès

    public function test_la_page_d_entree_renvoie_vers_le_lien_personnel(): void
    {
        $this->get(route('correction.entry'))
            ->assertOk()
            ->assertSee('lien personnel')
            ->assertSee('référence');
    }

    public function test_un_code_inconnu_ne_mene_a_rien(): void
    {
        $this->get('/correction/ZZZZZZZZ')->assertNotFound();
    }

    public function test_la_page_d_acces_nomme_la_mission_sans_montrer_les_copies(): void
    {
        $attempt = $this->playCopy($this->quiz);

        $this->get('/correction/'.$this->grader->link_code)
            ->assertOk()
            ->assertSee('Examen de Chimie')
            ->assertSee('Awa Kouassi')
            // Les copies ne se montrent pas avant l'authentification.
            ->assertDontSee($attempt->reference);
    }

    public function test_le_lien_seul_ne_suffit_pas(): void
    {
        $this->flushSession();

        // Bon email, mauvaise référence : le message reste le même que pour un
        // email inconnu, sinon il dirait lequel des deux a été trouvé.
        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => $this->grader->email,
            'reference' => 'ABCDEFGHJK',
        ])->assertSessionHasErrors('reference')->assertSessionMissing('grader');

        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => 'inconnu@test.com',
            'reference' => $this->grader->reference,
        ])->assertSessionHasErrors('reference')->assertSessionMissing('grader');
    }

    public function test_la_connexion_ouvre_l_espace_et_laisse_une_trace(): void
    {
        $attempt = $this->playCopy($this->quiz);

        $this->login();

        $this->assertSame($this->grader->id, session('grader.id'));

        $this->grader->refresh();

        $this->assertNotNull($this->grader->last_seen_at);
        $this->assertSame('127.0.0.1', $this->grader->last_ip);

        $this->get(route('correction.index'))
            ->assertOk()
            ->assertSee($attempt->reference)
            ->assertSee('Examen de Chimie');
    }

    public function test_la_reference_se_saisit_sans_casse_ni_espaces(): void
    {
        $this->flushSession();

        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => 'AWA@test.com',
            'reference' => mb_strtolower(implode(' ', str_split($this->grader->reference))),
        ])->assertRedirect(route('correction.index'))->assertSessionHasNoErrors();
    }

    public function test_un_correcteur_n_atteint_aucune_page_d_administration(): void
    {
        $this->playCopy($this->quiz);
        $this->login();

        // Il n'est pas administrateur : sa session ne s'ouvre pas sur /admin.
        $this->get(route('admin.dashboard'))->assertRedirect('/login');
        $this->get(route('admin.quizzes.results', $this->quiz))->assertRedirect('/login');
        $this->get(route('admin.quizzes.graders', $this->quiz))->assertRedirect('/login');
    }

    public function test_les_tentatives_repetees_sont_freinees(): void
    {
        $this->flushSession();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('correction.authenticate'), [
                'code' => $this->grader->link_code,
                'email' => $this->grader->email,
                'reference' => 'ABCDEFGHJK',
            ])->assertSessionHasErrors('reference');
        }

        // Sixième tentative dans la même minute : le frein se ferme.
        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => $this->grader->email,
            'reference' => 'ABCDEFGHJK',
        ])->assertStatus(429);
    }

    public function test_l_echeance_ferme_l_acces_sans_rien_detruire(): void
    {
        $this->playCopy($this->quiz);
        $this->login();

        Carbon::setTestNow(Carbon::now()->addDays(4));

        // Même connecté, un correcteur dont l'échéance est passée est refusé :
        // c'est la requête qui décide, aucune tâche planifiée n'est nécessaire.
        $this->get(route('correction.index'))->assertRedirect(route('correction.entry'));
        $this->assertNull(session('grader'));
    }

    public function test_la_deconnexion_ferme_l_espace(): void
    {
        $this->login();

        $this->post(route('correction.logout'))->assertRedirect(route('correction.entry'));

        $this->assertNull(session('grader'));
        $this->get(route('correction.index'))->assertRedirect(route('correction.entry'));
    }

    // -------------------------------------------------------------- Périmètre

    public function test_le_correcteur_ne_voit_pas_les_evaluations_non_affectees(): void
    {
        $mienne = $this->playCopy($this->quiz);
        $autre = $this->playCopy($this->autreQuiz, 'Turing Alan');

        $this->login();

        $this->get(route('correction.index'))
            ->assertOk()
            ->assertSee($mienne->reference)
            ->assertDontSee($autre->reference);

        // Et la copie de l'autre évaluation ne s'ouvre pas, même en connaissant
        // son identifiant.
        $this->get(route('correction.show', $autre))->assertNotFound();
        $this->post(route('correction.store', $autre), ['points' => []])->assertNotFound();
    }

    public function test_un_correcteur_ne_peut_pas_lire_l_espace_d_un_autre(): void
    {
        $autre = Grader::createFor('Boris Traoré', 'boris@test.com', Carbon::now()->addDays(3), $this->admin->id);
        $this->playCopy($this->quiz);

        $this->flushSession();

        // Le code de Boris avec la référence d'Awa : refusé.
        $this->post(route('correction.authenticate'), [
            'code' => $autre->link_code,
            'email' => $autre->email,
            'reference' => $this->grader->reference,
        ])->assertSessionHasErrors('reference');
    }

    // ------------------------------------------------------------- Correction

    public function test_le_correcteur_note_et_commente_la_copie(): void
    {
        $attempt = $this->playCopy($this->quiz);
        $answer = $this->openAnswer($attempt);

        $this->login();

        $this->post(route('correction.store', $attempt), [
            'points' => [$answer->id => '2'],
            'comments' => [$answer->id => 'Les deux étapes sont justes, le calcul final est faux.'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $attempt->refresh();
        $answer->refresh();

        $this->assertSame('2.00', $attempt->score);
        $this->assertSame('2.00', $answer->points_awarded);
        $this->assertSame('Les deux étapes sont justes, le calcul final est faux.', $answer->grader_comment);

        // L'auteur de la note est enregistré : c'est ce qui rend l'export du
        // correcteur possible, et une note contestée défendable.
        $this->assertSame($this->grader->id, $answer->graded_by_grader_id);
        $this->assertNull($answer->graded_by_admin_id);
        $this->assertSame('Awa Kouassi (correcteur)', $answer->gradedByLabel());
    }

    public function test_une_note_superieure_au_bareme_est_refusee(): void
    {
        $attempt = $this->playCopy($this->quiz);
        $answer = $this->openAnswer($attempt);

        $this->login();

        $this->post(route('correction.store', $attempt), [
            'points' => [$answer->id => '99'],
        ])->assertSessionHasErrors('points.'.$answer->id);

        $this->assertNull($answer->refresh()->points_awarded);
    }

    public function test_un_commentaire_sans_note_est_conserve(): void
    {
        $attempt = $this->playCopy($this->quiz);
        $answer = $this->openAnswer($attempt);

        $this->login();

        $this->post(route('correction.store', $attempt), [
            'points' => [$answer->id => ''],
            'comments' => [$answer->id => 'À revoir avec la collègue.'],
        ])->assertSessionHasNoErrors();

        $answer->refresh();

        // Le perdre silencieusement serait le pire des comportements pour qui
        // vient de le rédiger : il est enregistré, et la réponse reste en attente.
        $this->assertSame('À revoir avec la collègue.', $answer->grader_comment);
        $this->assertNull($answer->points_awarded);
        $this->assertSame($this->grader->id, $answer->graded_by_grader_id);
        $this->assertSame(1, $attempt->refresh()->pendingManualCount());
    }

    public function test_la_serie_enchaine_les_copies_du_correcteur(): void
    {
        $premiere = $this->playCopy($this->quiz, 'Curie Marie');
        $seconde = $this->playCopy($this->quiz, 'Turing Alan', 'On chauffe les corps gras avec une base.');

        $this->login();

        $this->get(route('correction.show', [$premiere, 'serie' => 1]))
            ->assertOk()
            ->assertSee('copie 1 sur 2');

        $this->post(route('correction.store', [$premiere, 'serie' => 1]), [
            'points' => [$this->openAnswer($premiere)->id => '3'],
        ])->assertRedirect(route('correction.show', [$seconde, 'serie' => 1]));

        // La dernière copie ramène à la liste, pas dans le vide.
        $this->post(route('correction.store', [$seconde, 'serie' => 1]), [
            'points' => [$this->openAnswer($seconde)->id => '1'],
        ])->assertRedirect(route('correction.index'));
    }

    // ----------------------------------------------------------------- Export

    public function test_l_export_ne_contient_que_les_copies_corrigees_par_ce_correcteur(): void
    {
        $mienne = $this->playCopy($this->quiz, 'Curie Marie');
        $autre = $this->playCopy($this->quiz, 'Turing Alan', 'Une réaction acide-base.');

        $autreCorrecteur = Grader::createFor('Boris Traoré', 'boris@test.com', Carbon::now()->addDays(3), $this->admin->id);
        $autreCorrecteur->forms()->attach($this->quiz->id);

        $this->login();

        $this->post(route('correction.store', $mienne), [
            'points' => [$this->openAnswer($mienne)->id => '3'],
            'comments' => [$this->openAnswer($mienne)->id => 'Réponse complète.'],
        ]);

        // L'autre correcteur corrige l'autre copie.
        session()->forget('grader');
        $this->post(route('correction.authenticate'), [
            'code' => $autreCorrecteur->link_code,
            'email' => $autreCorrecteur->email,
            'reference' => $autreCorrecteur->reference,
        ]);
        $this->post(route('correction.store', $autre), [
            'points' => [$this->openAnswer($autre)->id => '1'],
            'comments' => [$this->openAnswer($autre)->id => 'Insuffisant.'],
        ]);

        // Retour sur le premier correcteur.
        session()->forget('grader');
        $this->post(route('correction.authenticate'), [
            'code' => $this->grader->link_code,
            'email' => $this->grader->email,
            'reference' => $this->grader->reference,
        ]);

        $response = $this->get(route('correction.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();

        // Le BOM, sans lequel Excel affiche « Ã‰valuation ».
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Sa note, son commentaire… et rien de l'autre correcteur.
        $this->assertStringContainsString($mienne->reference, $csv);
        $this->assertStringContainsString('Réponse complète.', $csv);
        $this->assertStringNotContainsString($autre->reference, $csv);
        $this->assertStringNotContainsString('Insuffisant.', $csv);
    }

    public function test_l_export_respecte_l_anonymat(): void
    {
        $this->quiz->update(['is_anonymous' => true]);
        $attempt = $this->playCopy($this->quiz, 'Curie Marie');

        $this->login();

        $this->post(route('correction.store', $attempt), [
            'points' => [$this->openAnswer($attempt)->id => '3'],
        ]);

        $csv = $this->get(route('correction.export'))->streamedContent();

        $this->assertStringContainsString($attempt->reference, $csv);
        $this->assertStringNotContainsString('Curie Marie', $csv);
    }

    public function test_l_export_reste_vide_tant_que_rien_n_est_corrige(): void
    {
        $this->playCopy($this->quiz);
        $this->login();

        $csv = $this->get(route('correction.export'))->streamedContent();

        // Que l'en-tête : aucune copie corrigée par lui ne peut y figurer.
        $this->assertStringContainsString('Mon commentaire', $csv);
        $this->assertStringNotContainsString("\n", trim($csv));
    }
}

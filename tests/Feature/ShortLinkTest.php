<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Les liens courts : /l/Ab12Cd34.
 *
 * Ce que ces tests protègent, c'est l'idée qu'un lien court ne soit pas une
 * porte dérobée : il mène exactement là où mène le lien long, il ne devine pas
 * l'adresse d'un autre étudiant, et il n'ouvre jamais une copie en cours.
 */
class ShortLinkTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ Lien d'accès

    public function test_le_lien_court_d_une_evaluation_redirige_vers_le_lien_long(): void
    {
        $quiz = $this->quiz();

        $response = $this->get(ShortLink::forQuiz($quiz)->url());

        $response->assertRedirect(route('quiz.start', $quiz->token));
        $response->assertStatus(302);
    }

    public function test_le_lien_court_d_un_formulaire_de_depot_redirige_vers_le_lien_long(): void
    {
        $deposit = $this->deposit();

        $this->get(ShortLink::forDeposit($deposit)->url())
            ->assertRedirect(route('submit.form', $deposit->token));
    }

    public function test_un_jeton_d_evaluation_ne_mene_pas_au_depot_de_travaux(): void
    {
        $quiz = $this->quiz();
        $deposit = $this->deposit();

        // Liens volontairement croisés : le type de la cible fait foi, comme sur
        // les routes longues.
        ShortLink::create([
            'code' => 'Croise12',
            'kind' => ShortLink::KIND_QUIZ,
            'form_id' => $deposit->id,
        ]);

        ShortLink::create([
            'code' => 'Croise34',
            'kind' => ShortLink::KIND_FORM,
            'form_id' => $quiz->id,
        ]);

        $this->get('/l/Croise12')->assertNotFound();
        $this->get('/l/Croise34')->assertNotFound();
    }

    public function test_un_code_inconnu_repond_404(): void
    {
        $this->get('/l/Inconnu2')->assertNotFound();
    }

    public function test_les_codes_inconnus_sont_limites_par_adresse(): void
    {
        // Les codes valides ne sont jamais comptés : une salle informatique
        // entière derrière une même connexion n'est donc pas freinée.
        for ($i = 1; $i <= 30; $i++) {
            $this->get('/l/Rate'.$i.'xyz')->assertNotFound();
        }

        $this->get('/l/Rate31xyz')->assertStatus(429);
    }

    public function test_un_code_valide_ne_consomme_pas_le_quota_des_codes_inconnus(): void
    {
        $quiz = $this->quiz();
        $link = ShortLink::forQuiz($quiz);

        for ($i = 1; $i <= 40; $i++) {
            $this->get($link->url())->assertRedirect(route('quiz.start', $quiz->token));
        }
    }

    // ----------------------------------------------------------- Lien stable

    public function test_le_lien_court_reste_le_meme_d_un_appel_a_l_autre(): void
    {
        $quiz = $this->quiz();

        $this->assertSame(ShortLink::forQuiz($quiz)->code, ShortLink::forQuiz($quiz)->code);
        $this->assertSame(1, $quiz->shortLinks()->count());
    }

    public function test_la_page_d_une_evaluation_affiche_le_lien_long_et_le_lien_court(): void
    {
        $this->asAdmin();

        $quiz = $this->quiz();
        $short = ShortLink::forQuiz($quiz);

        $response = $this->get(route('admin.quizzes.show', $quiz));

        $response->assertOk();
        $response->assertSee(route('quiz.start', $quiz->token), false);
        $response->assertSee($short->url(), false);
        $response->assertSee('Copier le lien court');
    }

    public function test_la_page_d_un_formulaire_affiche_le_lien_long_et_le_lien_court(): void
    {
        $this->asAdmin();

        $deposit = $this->deposit();
        $short = ShortLink::forDeposit($deposit);

        $response = $this->get(route('admin.forms.show', $deposit));

        $response->assertOk();
        $response->assertSee(route('submit.form', $deposit->token), false);
        $response->assertSee($short->url(), false);
    }

    // -------------------------------------------------------- Lien de résultat

    public function test_le_lien_de_resultat_montre_la_copie_sans_session(): void
    {
        $quiz = $this->quiz();
        $attempt = $this->attempt($quiz, [
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 4,
            'max_score' => 5,
        ]);

        $response = $this->get(ShortLink::forAttempt($quiz, $attempt)->url());

        $response->assertOk();
        $response->assertSee($attempt->reference);
        $response->assertSee(ShortLink::forAttempt($quiz, $attempt)->url(), false);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_le_lien_de_resultat_ne_remplit_pas_la_session(): void
    {
        $quiz = $this->quiz();
        $attempt = $this->finished($quiz);

        $this->get(ShortLink::forAttempt($quiz, $attempt)->url())
            ->assertOk()
            // Le poste n'est pas rattaché à cette copie : un lien reçu par
            // message ne peut pas prendre la place d'une épreuve en cours.
            ->assertSessionMissing('quiz_attempt.'.$quiz->id)
            // Et rien à détacher, donc rien à proposer.
            ->assertDontSee("Ce n'est pas ma copie", false);
    }

    public function test_le_lien_d_une_copie_en_cours_ne_montre_rien(): void
    {
        $quiz = $this->quiz();
        $attempt = $this->attempt($quiz, [
            'status' => QuizAttempt::STATUS_IN_PROGRESS,
            'started_at' => Carbon::now(),
        ]);

        $link = ShortLink::forAttempt($quiz, $attempt);

        $this->get($link->url())->assertRedirect(route('quiz.start', $quiz->token));
    }

    public function test_l_administration_cree_puis_retrouve_le_lien_d_un_resultat(): void
    {
        $this->asAdmin();

        $quiz = $this->quiz();
        $attempt = $this->finished($quiz);

        $first = $this->post(route('admin.quizzes.attempts.result-link', [$quiz, $attempt]));

        $first->assertOk();
        $first->assertJsonStructure(['url']);

        $second = $this->post(route('admin.quizzes.attempts.result-link', [$quiz, $attempt]));

        $this->assertSame($first->json('url'), $second->json('url'));
        $this->assertSame(1, $quiz->shortLinks()->where('kind', ShortLink::KIND_RESULT)->count());
    }

    public function test_la_regeneration_annule_l_ancien_lien(): void
    {
        $this->asAdmin();

        $quiz = $this->quiz();
        $attempt = $this->finished($quiz);

        $old = $this->post(route('admin.quizzes.attempts.result-link', [$quiz, $attempt]))->json('url');

        $this->get($old)->assertOk();

        $new = $this->post(route('admin.quizzes.attempts.result-link.regenerate', [$quiz, $attempt]))->json('url');

        $this->assertNotSame($old, $new);
        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
    }

    public function test_le_lien_d_un_resultat_demande_une_copie_rendue(): void
    {
        $this->asAdmin();

        $quiz = $this->quiz();
        $attempt = $this->attempt($quiz, ['status' => QuizAttempt::STATUS_IN_PROGRESS, 'started_at' => Carbon::now()]);

        // Code inconnu : un lien de suivi n'a de sens qu'après la remise.
        $this->post(route('admin.quizzes.attempts.result-link', [$quiz, $attempt]))->assertNotFound();
    }

    public function test_le_lien_de_resultat_d_une_autre_evaluation_repond_404(): void
    {
        $this->asAdmin();

        $quiz = $this->quiz();
        $other = $this->quiz(['title' => 'Autre examen']);
        $attempt = $this->finished($quiz);

        $this->post(route('admin.quizzes.attempts.result-link', [$other, $attempt]))->assertNotFound();
    }

    public function test_la_page_de_resultat_propose_le_lien_de_suivi_et_son_qr(): void
    {
        $quiz = $this->quiz();
        $attempt = $this->finished($quiz);

        $this->session(['quiz_attempt.'.$quiz->id => $attempt->id]);

        $link = ShortLink::forAttempt($quiz, $attempt);

        $response = $this->get(route('quiz.result', $quiz->token));

        $response->assertOk();
        $response->assertSee('Votre lien de suivi');
        $response->assertSee($link->url(), false);
        $response->assertSee('data:image/png;base64,', false);
    }

    public function test_le_recapitulatif_pdf_emporte_le_lien_de_suivi(): void
    {
        $quiz = $this->quiz();
        $attempt = $this->finished($quiz);

        $link = ShortLink::forAttempt($quiz, $attempt);

        $response = $this->get(route('quiz.recap.pdf', [$quiz->token, $attempt->reference]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $rendered = view('student.quiz.recap-pdf', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'pending' => 0,
            'followLink' => $link,
            'followQr' => QrPng::dataUri($link->url()),
        ])->render();

        $this->assertStringContainsString($link->url(), $rendered);
        $this->assertStringContainsString('data:image/png;base64,', $rendered);
    }

    // -------------------------------------------------------------- Suppression

    public function test_les_liens_disparaissent_avec_le_formulaire(): void
    {
        $quiz = $this->quiz();
        $attempt = $this->finished($quiz);

        $link = ShortLink::forAttempt($quiz, $attempt)->url();

        $quiz->delete();

        $this->assertSame(0, ShortLink::count());
        $this->get($link)->assertNotFound();
    }

    // ------------------------------------------------------------- Utilitaires

    private function asAdmin(): void
    {
        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);
    }

    private function quiz(array $attributes = []): Form
    {
        return Form::create(array_merge([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function deposit(): Form
    {
        return Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'DEPOT-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);
    }

    private function finished(Form $quiz): QuizAttempt
    {
        return $this->attempt($quiz, [
            'status' => QuizAttempt::STATUS_SUBMITTED,
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
            'score' => 4,
            'max_score' => 5,
        ]);
    }

    private function attempt(Form $quiz, array $attributes = []): QuizAttempt
    {
        return $quiz->attempts()->create(array_merge([
            'reference' => $this->reference(),
        ], $attributes));
    }

    /**
     * Une référence valide : dix caractères, sans 0, 1, I, L ni O — l'alphabet
     * que QuizReference sait relire.
     */
    private function reference(): string
    {
        return 'REF'.strtr((string) (2000000 + ++$this->counter), ['0' => '2', '1' => '3']);
    }
}

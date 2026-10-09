<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Aperçu enseignant de la page d'attente.
 *
 * L'intérêt de cet écran tient tout entier à ce qu'il rend : la **vraie** page
 * étudiante, avec ses vrais chiffres. Ces tests vérifient donc qu'elle est bien
 * réelle, qu'aucune participation n'est créée au passage, et que ce qui doit
 * rester inerte le reste — rien ne sonne, rien ne se recharge, rien n'est retenu.
 *
 * Le son et le rechargement sont des comportements de script : le serveur les
 * commande par deux marques (data-preview, et l'absence de data-notice-key), et
 * c'est sur ces marques que portent les assertions.
 */
class QuizWaitingPreviewTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00:00'));

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function quiz(array $attributes = []): Form
    {
        return Form::create(array_merge([
            'title' => 'Examen de Comptabilité',
            'token' => 'JETON-APERCU-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => false],
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function quizWithReferences(array $attributes = []): Form
    {
        $quiz = $this->quiz($attributes);
        $quiz->markReferencesPrepared();

        return $quiz;
    }

    private function question(Form $quiz): FormField
    {
        return $quiz->fields()->create([
            'field_label' => 'Question unique',
            'field_type' => 'radio',
            'required' => true,
            'order' => 1,
            'options' => ['Un', 'Deux'],
            'correct_answer' => [1],
            'points' => 1,
        ]);
    }

    private function deposit(): Form
    {
        return Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'DEPOT-APERCU-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);
    }

    // -------------------------------------------------------- La vraie page

    public function test_l_apercu_montre_la_vraie_page_d_attente_avec_son_vrai_decompte(): void
    {
        // Ouverture dans trente minutes : c'est le décompte que verra l'étudiant.
        $quiz = $this->quizWithReferences(['open_date' => Carbon::parse('2026-10-12 08:30:10')]);
        $this->question($quiz);

        $this->get(route('admin.quizzes.waiting-preview', $quiz))
            ->assertOk()
            ->assertSee('Aperçu de la page d')
            ->assertSee('id="quiz-open-countdown"', false)
            // Les mêmes chiffres que pour l'étudiant : ceux du serveur.
            ->assertSee('data-seconds="1810"', false)
            ->assertSee('tabular-nums">00:30:10</p>', false)
            ->assertSee('12/10/2026 à 08:30')
            // Et ce que l'étudiant peut déjà saisir pendant l'attente.
            ->assertSee('Préparez votre entrée')
            ->assertSee('Nom complet')
            ->assertSee('Votre référence');
    }

    public function test_l_apercu_ne_cree_aucune_participation_et_ne_retient_rien(): void
    {
        $quiz = $this->quizWithReferences(['open_date' => Carbon::parse('2026-10-12 09:00:00')]);
        $this->question($quiz);

        $this->get(route('admin.quizzes.waiting-preview', $quiz))->assertOk();

        // Un aperçu ne prépare personne : ni copie, ni information retenue en
        // session, même avec la liste prête et les champs à l'écran.
        $this->assertSame(0, $quiz->attempts()->count());
        $this->assertNull(session('quiz_waiting.'.$quiz->id));
    }

    public function test_les_champs_de_l_apercu_sont_inertes(): void
    {
        $quiz = $this->quizWithReferences(['open_date' => Carbon::parse('2026-10-12 09:00:00')]);
        $this->question($quiz);

        $response = $this->get(route('admin.quizzes.waiting-preview', $quiz))->assertOk();

        // Les quatre champs — référence, nom, email, filière — sont affichés, et
        // aucun n'est modifiable.
        $this->assertSame(4, substr_count($response->getContent(), 'aria-disabled="true"'));

        // Le formulaire d'aperçu ne mène nulle part : il ne peut donc rien
        // enregistrer, aucune session d'étudiant n'existant derrière.
        $response
            ->assertSee('Retenir mes informations')
            ->assertDontSee('action="'.route('quiz.prepare', $quiz->token).'"', false)
            ->assertSee('onsubmit="return false"', false);
    }

    public function test_l_apercu_ne_sonne_pas_et_ne_recharge_pas(): void
    {
        $quiz = $this->quizWithReferences(['open_date' => Carbon::parse('2026-10-12 09:00:00')]);
        $this->question($quiz);

        $this->get(route('admin.quizzes.waiting-preview', $quiz))
            ->assertOk()
            ->assertSee('data-preview="1"', false)
            ->assertSee('data-preview-note="Aperçu', false)
            // Sans trace laissée par une page d'attente, la page ouverte ne peut
            // ni clignoter ni afficher son bandeau : l'aperçu ne les porte pas.
            ->assertDontSee('data-notice-key', false)
            ->assertDontSee('id="quiz-opened-notice"', false);
    }

    public function test_sans_ouverture_programmee_le_decompte_est_simule(): void
    {
        // Épreuve déjà ouverte : il n'y a pas d'ouverture à décompter, et la carte
        // doit pourtant montrer quelque chose — un décompte simulé, annoncé comme
        // tel, plutôt qu'une carte vide ou un chiffre inventé sans le dire.
        $quiz = $this->quizWithReferences();
        $this->question($quiz);

        $this->assertNull($quiz->quizOpensInSeconds());

        $this->get(route('admin.quizzes.waiting-preview', $quiz))
            ->assertOk()
            ->assertSee('data-seconds="300"', false)
            ->assertSee('tabular-nums">00:05:00</p>', false)
            ->assertSee("Aucune ouverture n'est programmée", false)
            ->assertSee('simulé');
    }

    public function test_l_apercu_simule_ne_fait_pas_croire_a_une_echeance(): void
    {
        // Le décompte simulé est annoncé comme tel : un lecteur d'écran ne doit pas
        // prendre la démonstration pour une heure d'ouverture réelle.
        $quiz = $this->quizWithReferences();
        $this->question($quiz);

        $this->get(route('admin.quizzes.waiting-preview', $quiz))
            ->assertOk()
            ->assertSee('Aperçu : le décompte ci-dessous est simulé.');
    }

    // ------------------------------------------------------------- Les accès

    public function test_la_page_de_l_evaluation_propose_l_apercu(): void
    {
        $quiz = $this->quizWithReferences();

        $this->get(route('admin.quizzes.show', $quiz))
            ->assertOk()
            ->assertSee(route('admin.quizzes.waiting-preview', $quiz));
    }

    public function test_l_apercu_refuse_un_depot_de_travaux(): void
    {
        $this->get(route('admin.quizzes.waiting-preview', $this->deposit()))->assertNotFound();
    }

    public function test_l_apercu_exige_une_session_administrateur(): void
    {
        $this->flushSession();

        $this->get(route('admin.quizzes.waiting-preview', $this->quiz()))
            ->assertRedirect(route('login'));
    }
}

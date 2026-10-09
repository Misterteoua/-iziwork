<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Salle d'attente : ce que l'étudiant saisit avant l'ouverture est retenu.
 *
 * Une épreuve qui n'a pas encore ouvert ne peut pas enregistrer de
 * participation. La page d'attente recueille donc seulement le nom et la
 * référence en session, et la page d'accès s'en trouve pré-remplie à l'heure
 * dite — c'est tout son objet, et ces tests le suivent de bout en bout : la
 * saisie, le pré-remplissage, et le fait que rien n'est ouvert par avance.
 *
 * La référence est vérifiée **dans sa forme, jamais dans son existence** : tant
 * que l'épreuve est fermée, la page ne doit pas permettre de savoir quelles
 * références ont été distribuées, et une liste pas encore chargée ne doit bloquer
 * personne.
 */
class QuizWaitingRoomTest extends TestCase
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
            'token' => 'JETON-ATTENTE-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            // Ouverture dans une heure : la salle d'attente est affichée.
            'open_date' => Carbon::parse('2026-10-12 09:00:00'),
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => false],
            'created_by' => $this->admin->id,
        ], $attributes));
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

    /** L'heure d'ouverture est passée : c'est la page de démarrage. */
    private function opening(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:01'));
    }

    // ------------------------------------------------------- La salle d'attente

    public function test_la_salle_d_attente_propose_de_retenir_nom_et_reference(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('Préparez votre entrée')
            ->assertSee('Retenir mes informations')
            ->assertSee('action="'.route('quiz.prepare', $quiz->token).'"', false)
            ->assertSee('Nom complet')
            ->assertSee('Votre référence')
            // Facultatifs pendant l'attente : rien de ce qui est saisi ici ne fait
            // entrer dans l'épreuve, et un champ obligatoire n'aurait pas de sens
            // pour quelqu'un qui veut seulement noter sa référence.
            ->assertDontSee('Commencer');
    }

    public function test_ce_qui_est_retenu_pre_remplit_le_formulaire_a_l_ouverture(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        // Saisie telle qu'un étudiant la tape : espaces et minuscules compris.
        $this->post(route('quiz.prepare', $quiz->token), [
            'reference' => 'abcd efgh jk',
            'student_name' => 'Curie Marie',
            'student_email' => 'Marie@Test.com',
            'student_major' => 'Informatique',
        ])->assertRedirect(route('quiz.start', $quiz->token));

        $this->opening();

        // À l'ouverture : le formulaire est là, déjà rempli. La référence y est
        // normalisée, exactement comme beginWithReference() la cherchera.
        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('Commencer')
            ->assertSee('value="ABCDEFGHJK"', false)
            ->assertSee('value="Curie Marie"', false)
            ->assertSee('value="marie@test.com"', false)
            ->assertSee('value="Informatique"', false);
    }

    public function test_une_reference_bien_formee_est_retenue_sans_etre_verifiee_en_base(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        // Aucune référence n'existe en base : la salle d'attente n'a pas à le
        // savoir. Refuser ici révélerait quelles références existent, et
        // bloquerait un étudiant dont la liste n'est pas encore chargée. C'est
        // beginWithReference() qui en décide, à l'ouverture, et lui seul.
        $this->post(route('quiz.prepare', $quiz->token), ['reference' => 'ABCDEFGHJK'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $quiz->attempts()->count());

        $this->opening();

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('value="ABCDEFGHJK"', false);
    }

    public function test_une_reference_mal_formee_est_refusee_des_l_attente(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        // La page, pour que le retour de l'erreur ait une adresse où revenir.
        $this->get(route('quiz.start', $quiz->token))->assertOk();

        $this->post(route('quiz.prepare', $quiz->token), ['reference' => 'trop-court'])
            ->assertSessionHasErrors('reference');

        // Le refus se lit sur la page d'accès, qui rend ce qui vient d'être tapé :
        // c'est le vieux formulaire, et cela ne prouve pas encore que quelque
        // chose a été retenu.
        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('Une référence comporte 10 caractères')
            ->assertSee('value="trop-court"', false);

        // La demande suivante, elle, ne porte plus rien : ce qui a été refusé
        // n'a été retenu nulle part, et l'ouverture se présente champ vide.
        $this->opening();

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertDontSee('trop-court');
    }

    public function test_l_evaluation_anonyme_ne_retient_aucun_nom(): void
    {
        $quiz = $this->quiz(['is_anonymous' => true]);
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        // La salle d'attente ne garde pas ce que l'épreuve refusera d'écrire :
        // l'anonymat tient en session comme il tient en base (voir beginFree()).
        $this->post(route('quiz.prepare', $quiz->token), [
            'reference' => 'ABCDEFGHJK',
            'student_name' => 'Curie Marie',
        ])->assertSessionHasNoErrors();

        $this->opening();

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('value="ABCDEFGHJK"', false)
            ->assertDontSee('Curie Marie');
    }

    public function test_retenir_ne_cree_aucune_participation_et_n_ouvre_rien(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        $this->post(route('quiz.prepare', $quiz->token), ['reference' => 'ABCDEFGHJK'])
            ->assertRedirect(route('quiz.start', $quiz->token));

        // Rien en base, et l'épreuve reste fermée : la salle d'attente est un
        // confort, pas une autorisation. La garde, elle, est dans begin().
        $this->assertSame(0, $quiz->attempts()->count());

        $this->post(route('quiz.begin', $quiz->token), ['reference' => 'ABCDEFGHJK'])
            ->assertSessionHas('error');

        $this->assertSame(0, $quiz->attempts()->count());
    }

    public function test_une_evaluation_fermee_ne_propose_pas_de_retenir_ses_informations(): void
    {
        // Désactivée : rien ne s'ouvrira, il n'y a donc rien à préparer — et
        // garder des informations pour une ouverture qui n'aura pas lieu serait
        // une promesse en l'air.
        $quiz = $this->quiz(['status' => 'inactive']);
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('pas ouverte')
            ->assertDontSee('Préparez votre entrée')
            ->assertDontSee('Retenir mes informations');
    }

    public function test_le_candidat_suivant_ne_recupere_pas_les_informations_du_precedent(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $question = $this->question($quiz);

        $quiz->attempts()->create(['reference' => 'ABCDEFGHJK']);

        $this->post(route('quiz.prepare', $quiz->token), [
            'reference' => 'ABCDEFGHJK',
            'student_name' => 'Curie Marie',
        ])->assertRedirect();

        $this->opening();

        // Le premier candidat démarre : ce que l'attente avait retenu a servi.
        $this->post(route('quiz.begin', $quiz->token), [
            'reference' => 'ABCDEFGHJK',
            'student_name' => 'Curie Marie',
        ])->assertRedirect(route('quiz.question', $quiz->token));

        $this->post(route('quiz.answer', $quiz->token), ['question_id' => $question->id, 'choice' => 1]);
        $this->post(route('quiz.submit', $quiz->token));

        // Salle informatique : le poste change de mains. Le nom du précédent ne
        // doit pas rester dans le formulaire, sinon le suivant commencerait avec
        // l'identité de quelqu'un d'autre.
        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertSee('Commencer')
            ->assertDontSee('value="Curie Marie"', false)
            ->assertDontSee('value="ABCDEFGHJK"', false);
    }

    public function test_le_nouveau_candidat_ne_recupere_pas_non_plus_ce_qui_a_ete_retenu(): void
    {
        $quiz = $this->quiz();
        $quiz->markReferencesPrepared();
        $this->question($quiz);

        $attempt = $quiz->attempts()->create([
            'reference' => 'ABCDEFGHJK',
            'student_name' => 'Jean',
            'status' => 'submitted',
            'started_at' => Carbon::now()->subMinutes(10),
            'submitted_at' => Carbon::now(),
        ]);

        $this->post(route('quiz.prepare', $quiz->token), [
            'reference' => 'ABCDEFGHJM',
            'student_name' => 'Awa',
        ])->assertRedirect();

        // La salle change de main par le bouton dédié : le poste est libéré, et
        // ce que l'attente avait retenu part avec le candidat précédent.
        $this->post(route('quiz.new-candidate', $quiz->token))->assertRedirect();

        $this->opening();

        $this->get(route('quiz.start', $quiz->token))
            ->assertOk()
            ->assertDontSee('value="Awa"', false);

        $this->assertNotNull($attempt->fresh());
    }
}

<?php

namespace Tests\Unit;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = AdminUser::create([
            'username' => 'testadmin',
            'email' => 'test@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_form_can_be_created(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'description' => 'A test form',
            'token' => 'test-token-123',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('forms', [
            'title' => 'Test Form',
            'token' => 'test-token-123',
        ]);
    }

    public function test_form_token_is_auto_generated(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotEmpty($form->token);
        $this->assertEquals(32, strlen($form->token));
    }

    public function test_form_has_fields_relationship(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        FormField::create([
            'form_id' => $form->id,
            'field_label' => 'Name',
            'field_type' => 'text',
            'required' => true,
            'order' => 0,
        ]);

        $this->assertCount(1, $form->fields);
    }

    public function test_form_has_submissions_relationship(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student@test.com',
            'status' => 'validated',
        ]);

        $this->assertCount(1, $form->submissions);
    }

    public function test_form_has_creator_relationship(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals($this->admin->id, $form->creator->id);
    }

    public function test_form_is_open_when_active_and_within_dates(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'open_date' => now()->subDay(),
            'close_date' => now()->addDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->isOpen());
    }

    public function test_form_is_not_open_when_inactive(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_not_open_when_before_open_date(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'open_date' => now()->addDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_not_open_when_after_close_date(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'close_date' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_not_open_when_max_submissions_reached(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'max_submissions' => 1,
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student@test.com',
            'status' => 'validated',
        ]);

        $this->assertFalse($form->isOpen());
    }

    public function test_form_is_open_when_no_dates_set(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->isOpen());
    }

    public function test_get_submission_count_returns_correct_count(): void
    {
        $form = Form::create([
            'title' => 'Test Form',
            'status' => 'inactive',
            'created_by' => $this->admin->id,
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student1@test.com',
            'status' => 'validated',
        ]);

        Submission::create([
            'form_id' => $form->id,
            'student_email' => 'student2@test.com',
            'status' => 'validated',
        ]);

        $this->assertEquals(2, $form->getSubmissionCount());
    }

    // ------------------------------------ Publication des corrections

    public function test_un_quiz_encore_ouvert_ne_publie_pas_ses_corrections(): void
    {
        $form = Form::create([
            'title' => 'Examen en cours',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'open_date' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->quizRevealsCorrection());
    }

    public function test_un_quiz_sans_date_de_fermeture_ne_publie_pas_ses_corrections(): void
    {
        // Cas courant : une évaluation ouverte sans échéance. Tant qu'elle reste
        // active, les réponses ne sortent pas — c'est précisément la situation où
        // les candidats se succèdent pendant des heures.
        $form = Form::create([
            'title' => 'Examen sans échéance',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->quizRevealsCorrection());
    }

    public function test_un_quiz_publie_ses_corrections_apres_la_date_de_fermeture(): void
    {
        $form = Form::create([
            'title' => 'Examen passé',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'close_date' => now()->subMinute(),
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->quizRevealsCorrection());
    }

    public function test_un_quiz_publie_ses_corrections_quand_il_est_desactive(): void
    {
        // Le bouton « Fermer l'évaluation » : le troisième levier, qui fonctionne
        // même sans aucune date.
        $form = Form::create([
            'title' => 'Examen désactivé',
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->quizRevealsCorrection());
    }

    public function test_une_date_de_publication_choisie_publie_sans_fermer_l_evaluation(): void
    {
        // Tous les candidats ont composé, mais l'épreuve reste ouverte une
        // semaine : l'enseignant publie quand il veut.
        $form = $this->quizWithPublication(now()->subMinute(), closeDate: now()->addWeek());

        $this->assertTrue($form->quizRevealsCorrection());
    }

    public function test_une_date_de_publication_a_venir_laisse_les_corrections_cachees(): void
    {
        $publication = now()->addDay();

        $form = $this->quizWithPublication($publication);

        $this->assertFalse($form->quizRevealsCorrection());
        $this->assertSame($publication->format('Y-m-d H:i'), $form->quizPublicationDate()?->format('Y-m-d H:i'));
        // C'est elle qu'on annonce à l'étudiant, faute d'échéance plus proche.
        $this->assertSame($publication->format('Y-m-d H:i'), $form->quizRevealMoment()?->format('Y-m-d H:i'));
    }

    public function test_l_echeance_annoncee_est_la_plus_proche_des_deux(): void
    {
        $form = $this->quizWithPublication(now()->addDays(3), closeDate: now()->addHours(2));

        $this->assertSame(
            now()->addHours(2)->format('Y-m-d H:i'),
            $form->quizRevealMoment()?->format('Y-m-d H:i')
        );
    }

    public function test_aucune_echeance_annoncee_quand_rien_n_est_fixe(): void
    {
        $form = Form::create([
            'title' => 'Examen sans échéance',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);

        $this->assertNull($form->quizPublicationDate());
        $this->assertNull($form->quizRevealMoment());
    }

    public function test_une_date_de_publication_illisible_est_ignoree(): void
    {
        // Une valeur abimée en base ne doit pas casser une page de résultat :
        // elle est ignorée, et la fermeture reprend la main.
        $form = Form::create([
            'title' => 'Examen abimé',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['reveal_answers_at' => 'pas-une-date'],
            'created_by' => $this->admin->id,
        ]);

        $this->assertNull($form->quizPublicationDate());
        $this->assertFalse($form->quizRevealsCorrection());
    }

    // ------------------------------------ Correction manuelle des QCM

    public function test_la_correction_manuelle_des_qcm_est_desactivee_par_defaut(): void
    {
        // Le défaut est le comportement historique : une évaluation créée avant
        // que ce réglage existe garde son auto-correction.
        $form = Form::create([
            'title' => 'Examen par défaut',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->quizGradesChoiceManually());
    }

    public function test_la_correction_manuelle_des_qcm_s_active_par_reglage(): void
    {
        $form = Form::create([
            'title' => 'Examen manuel',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['manual_choice_grading' => true],
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->quizGradesChoiceManually());
    }

    // ------------------------------------ Signal sonore de la page d'attente

    public function test_le_signal_sonore_de_l_attente_est_actif_par_defaut(): void
    {
        // Le défaut est le comportement d'origine : une évaluation créée avant que
        // ce réglage existe continue de faire retentir son signal à l'ouverture.
        $form = Form::create([
            'title' => 'Examen par défaut',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);

        $this->assertTrue($form->quizPlaysOpeningSound());
    }

    public function test_le_signal_sonore_de_l_attente_se_retire_par_reglage(): void
    {
        $form = Form::create([
            'title' => 'Examen silencieux',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['opening_sound' => false],
            'created_by' => $this->admin->id,
        ]);

        $this->assertFalse($form->quizPlaysOpeningSound());
    }

    private function quizWithPublication(\DateTimeInterface $publication, ?\DateTimeInterface $closeDate = null): Form
    {
        return Form::create([
            'title' => 'Examen avec publication',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'close_date' => $closeDate,
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'reveal_answers_at' => $publication->format('Y-m-d H:i:s'),
            ],
            'created_by' => $this->admin->id,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mise en page du récapitulatif PDF de dépôt de travaux.
 *
 * Même identité visuelle que le récapitulatif d'évaluation : logo de
 * l'établissement en haut, logo Iziwork dans un pied de page répété. Ce fichier
 * vérifie l'habillage, et surtout qu'aucune information du reçu n'a disparu —
 * un document que l'étudiant garde doit rester complet.
 */
class RecapDepotPdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $form;

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

        $this->form = Form::create([
            'title' => 'Dépôt de rapport — Génie civil',
            'description' => 'Rapport de fin de module.',
            'token' => 'JETON-DEPOT-'.uniqid(),
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->form->fields()->create([
            'field_label' => 'Nom complet',
            'field_type' => 'text',
            'required' => true,
            'order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function submission(bool $withFile = false): Submission
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => 'John Doe',
            'student_email' => 'john@test.com',
            'status' => 'validated',
        ]);

        if ($withFile) {
            SubmissionFile::create([
                'submission_id' => $submission->id,
                'field_label' => 'Rapport',
                'original_name' => 'rapport-final.pdf',
                'stored_name' => 'uuid.pdf',
                'file_path' => 'submissions/1/1/uuid.pdf',
                'file_size' => 1_500_000,
                'mime_type' => 'application/pdf',
            ]);
        }

        return $submission->load('files');
    }

    private function render(Submission $submission): string
    {
        return view('student.recap-pdf', [
            'form' => $this->form,
            'submission' => $submission,
        ])->render();
    }

    // ------------------------------------------------------------------ Logos

    public function test_le_recapitulatif_de_depot_porte_les_deux_logos(): void
    {
        $html = $this->render($this->submission());

        // Logo de l'établissement en haut, avant le titre.
        $this->assertStringContainsString('alt="EFSC"', $html);
        $this->assertLessThan(
            strpos($html, 'Récapitulatif de dépôt'),
            strpos($html, 'alt="EFSC"'),
            'Le logo doit précéder le titre du récapitulatif.'
        );

        // Logo Iziwork en pied de page, répété sur chaque page.
        $this->assertStringContainsString('alt="Iziwork"', $html);
        $this->assertStringContainsString('page-footer', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('Document généré automatiquement par Iziwork', $html);
    }

    public function test_le_recapitulatif_de_depot_se_genere_toujours(): void
    {
        $submission = $this->submission(withFile: true);

        $response = $this->get("/s/{$this->form->token}/recap/{$submission->receipt_token}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    // ------------------------------------------------- Contenu préservé

    public function test_la_refonte_conserve_toutes_les_informations_du_recu(): void
    {
        $submission = $this->submission(withFile: true);
        $html = $this->render($submission);

        // Ce que l'étudiant doit retrouver, quoi qu'il arrive à l'habillage.
        $this->assertStringContainsString('Dépôt de rapport — Génie civil', $html);
        $this->assertStringContainsString('Rapport de fin de module.', $html);
        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('Validée', $html);
        $this->assertStringContainsString('rapport-final.pdf', $html);
        $this->assertStringContainsString('1.43 Mo', $html);
        $this->assertStringContainsString('Informations du formulaire', $html);
        $this->assertStringContainsString('Informations de l\'étudiant', $html);
    }

    public function test_le_code_anonyme_reste_lisible(): void
    {
        $submission = Submission::create([
            'form_id' => $this->form->id,
            'anonymous_code' => 'ANON-ABC123',
            'status' => 'pending',
        ])->load('files');

        $html = $this->render($submission);

        $this->assertStringContainsString('ANON-ABC123', $html);
        $this->assertStringContainsString('Code anonyme', $html);
        // Statut « en attente » : le badge existe pour les deux états.
        $this->assertStringContainsString('En attente', $html);
    }

    public function test_la_section_des_fichiers_est_absente_sans_fichier(): void
    {
        $html = $this->render($this->submission(withFile: false));

        // Le titre de la section porte le compteur : « Fichiers déposés (n) ».
        // Chercher la seule expression « Fichiers déposés » trouverait aussi le
        // commentaire CSS qui la nomme — d'où la parenthèse, qui tranche.
        $this->assertStringNotContainsString('Fichiers déposés (', $html);
        $this->assertStringContainsString('Dépôt enregistré', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le ZIP de téléchargement suit les filtres actifs de la page : un
 * administrateur qui isole « les dépôts en attente de cette semaine » doit
 * récupérer ceux-là, pas tout le formulaire.
 */
class SubmissionBulkDownloadFilterTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $form;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-19 10:00:00'));

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

        $this->form = Form::create([
            'title' => 'MATHS',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Storage::disk('local')->deleteDirectory('submissions');

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    /**
     * Une soumission avec un fichier réellement déposé sur le disque « local »,
     * nommé d'après l'étudiant dans l'archive.
     */
    private function submission(string $createdAt, string $status = 'validated', string $name = 'Jean Dupont'): Submission
    {
        $this->counter++;

        $submission = Submission::create([
            'form_id' => $this->form->id,
            'student_name' => $name,
            'student_email' => 'etudiant'.$this->counter.'@test.com',
            'status' => $status,
        ]);

        $submission->created_at = Carbon::parse($createdAt);
        $submission->save();

        $storedName = 'devoir-'.$this->counter.'.pdf';
        $path = "submissions/{$this->form->id}/{$submission->id}/{$storedName}";

        UploadedFile::fake()->create($storedName, 10, 'application/pdf')
            ->storeAs(dirname($path), $storedName, 'local');

        // Le nom dans l'archive est le nom d'origine, pas le nom stocké :
        // l'étudiant retrouve le fichier qu'il a déposé.
        SubmissionFile::create([
            'submission_id' => $submission->id,
            'field_label' => 'DEVOIR',
            'original_name' => 'devoir.pdf',
            'stored_name' => $storedName,
            'file_path' => $path,
            'file_size' => 10,
            'mime_type' => 'application/pdf',
        ]);

        return $submission;
    }

    private function download(array $query = []): TestResponse
    {
        $url = "/admin/forms/{$this->form->id}/submissions/bulk-download";

        return $this->get($query === [] ? $url : $url.'?'.http_build_query($query));
    }

    /**
     * Contenu de l'archive, indexé par nom d'entrée, puis archive supprimée
     * (deleteFileAfterSend n'intervient qu'à l'envoi réel de la réponse).
     *
     * @return array<int, string>
     */
    private function entries(TestResponse $response): array
    {
        $path = $response->getFile()->getPathname();

        $zip = new \ZipArchive;
        $zip->open($path);

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $names;
    }

    // ------------------------------------------------------------------- Tests

    public function test_sans_filtre_l_archive_contient_tout_le_formulaire(): void
    {
        $this->submission('2026-09-18 08:00:00', 'validated', 'Jean Dupont');
        $this->submission('2026-09-17 08:00:00', 'pending', 'Marie Curie');

        $response = $this->download();

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');

        $names = $this->entries($response);

        $this->assertCount(2, $names);
        $this->assertContains('Dupont_Jean/DEVOIR/devoir.pdf', $names);
        $this->assertContains('Curie_Marie/DEVOIR/devoir.pdf', $names);
    }

    public function test_l_archive_suit_le_filtre_statut(): void
    {
        $this->submission('2026-09-18 08:00:00', 'validated', 'Jean Dupont');
        $this->submission('2026-09-17 08:00:00', 'pending', 'Marie Curie');

        $names = $this->entries($this->download(['status' => 'pending']));

        $this->assertSame(['Curie_Marie/DEVOIR/devoir.pdf'], $names);
    }

    public function test_l_archive_suit_le_filtre_periode(): void
    {
        $this->submission('2026-09-19 09:00:00', 'validated', 'Jean Dupont');
        $this->submission('2026-09-05 09:00:00', 'validated', 'Marie Curie');

        $names = $this->entries($this->download(['period' => '7d']));

        $this->assertSame(['Dupont_Jean/DEVOIR/devoir.pdf'], $names);
    }

    public function test_l_archive_suit_la_plage_de_dates_avec_bornes_incluses(): void
    {
        $this->submission('2026-09-10 00:00:00', 'validated', 'Jean Dupont');   // incluse
        $this->submission('2026-09-12 23:59:59', 'validated', 'Marie Curie');   // incluse
        $this->submission('2026-09-13 00:00:00', 'validated', 'Ada Lovelace');  // exclue

        $names = $this->entries($this->download(['from' => '2026-09-10', 'to' => '2026-09-12']));

        $this->assertCount(2, $names);
        $this->assertContains('Dupont_Jean/DEVOIR/devoir.pdf', $names);
        $this->assertContains('Curie_Marie/DEVOIR/devoir.pdf', $names);
        $this->assertNotContains('Lovelace_Ada/DEVOIR/devoir.pdf', $names);
    }

    public function test_l_archive_suit_les_filtres_combines(): void
    {
        $this->submission('2026-09-19 08:00:00', 'pending', 'Jean Dupont');
        $this->submission('2026-09-19 09:00:00', 'validated', 'Marie Curie');
        $this->submission('2026-08-01 09:00:00', 'pending', 'Ada Lovelace');

        $names = $this->entries($this->download(['period' => 'today', 'status' => 'pending']));

        $this->assertSame(['Dupont_Jean/DEVOIR/devoir.pdf'], $names);
    }

    public function test_l_archive_suit_la_recherche(): void
    {
        $this->submission('2026-09-18 08:00:00', 'validated', 'Jean Dupont');
        $this->submission('2026-09-17 08:00:00', 'validated', 'Marie Curie');

        $names = $this->entries($this->download(['search' => 'curie']));

        $this->assertSame(['Curie_Marie/DEVOIR/devoir.pdf'], $names);
    }

    public function test_les_parametres_invalides_sont_ignores_dans_l_archive(): void
    {
        $this->submission('2026-09-18 08:00:00', 'validated', 'Jean Dupont');

        $response = $this->get(
            "/admin/forms/{$this->form->id}/submissions/bulk-download?period=zzz&from=2026-13-45&status=zzz"
        );

        $this->assertCount(1, $this->entries($response));
    }

    public function test_un_filtre_sans_resultat_refuse_de_livrer_une_archive_vide(): void
    {
        $this->submission('2026-09-18 08:00:00', 'validated', 'Jean Dupont');

        $response = $this->download(['status' => 'pending']);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Aucune archive n'a été produite : rien à nettoyer dans storage.
        $this->assertSame([], Storage::disk('local')->files('submissions'));
    }

    public function test_des_pieces_jointes_absentes_du_disque_ne_provoquent_pas_d_erreur_500(): void
    {
        // Les lignes existent en base mais les fichiers ont disparu du disque
        // (restauration partielle, nettoyage manuel). ZipArchive n'écrit alors
        // aucune archive : mieux vaut le dire que lever une erreur 500.
        $submission = $this->submission('2026-09-18 08:00:00', 'validated', 'Jean Dupont');

        Storage::disk('local')->deleteDirectory("submissions/{$this->form->id}/{$submission->id}");

        $response = $this->download();

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame([], Storage::disk('local')->files('submissions'));
    }

    public function test_la_page_embarque_les_filtres_dans_le_lien_de_telechargement(): void
    {
        // Une soumission qui correspond, sinon le bouton n'est pas affiché.
        $this->submission('2026-09-19 08:00:00', 'pending', 'Jean Dupont');

        $response = $this->get(
            "/admin/forms/{$this->form->id}/submissions?period=7d&status=pending"
        );

        $bulkUrl = $response->viewData('bulkUrl');

        $this->assertStringContainsString('period=7d', $bulkUrl);
        $this->assertStringContainsString('status=pending', $bulkUrl);

        $response->assertSee('Télécharger la sélection (ZIP)');
    }

    public function test_sans_filtre_le_lien_de_telechargement_ne_contient_aucun_parametre(): void
    {
        $this->submission('2026-09-18 08:00:00');

        $response = $this->get("/admin/forms/{$this->form->id}/submissions");

        $this->assertSame(
            route('admin.submissions.bulk', $this->form),
            $response->viewData('bulkUrl')
        );

        $response->assertSee('Télécharger tout (ZIP)');
    }
}

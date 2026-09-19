<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Export CSV du tableau de bord : il doit contenir exactement le résultat des
 * filtres actifs, et rien d'autre.
 */
class SubmissionExportTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $maths;

    private Form $physique;

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

        $this->maths = Form::create([
            'title' => 'MATHS',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->physique = Form::create([
            'title' => 'PHYSIQUE',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function export(array $query = []): TestResponse
    {
        $url = '/admin/export/submissions';

        return $this->get($query === [] ? $url : $url.'?'.http_build_query($query));
    }

    private function submission(
        Form $form,
        string $createdAt,
        string $status = 'validated',
        array $attributes = [],
    ): Submission {
        $this->counter++;

        $submission = Submission::create(array_merge([
            'form_id' => $form->id,
            'student_name' => 'Etudiant '.$this->counter,
            'student_email' => 'etudiant'.$this->counter.'@test.com',
            'student_major' => 'GL',
            'status' => $status,
        ], $attributes));

        $submission->created_at = Carbon::parse($createdAt);
        $submission->save();

        return $submission;
    }

    /**
     * Découpe le CSV en lignes, BOM retiré.
     *
     * @return array<int, string>
     */
    private function rows(TestResponse $response): array
    {
        $csv = str_replace("\xEF\xBB\xBF", '', $response->streamedContent());

        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", trim($csv)))));
    }

    // ------------------------------------------------------------------- Accès

    public function test_l_export_reste_reserve_a_l_admin(): void
    {
        $this->flushSession();

        $this->get('/admin/export/submissions')->assertRedirect('/login');
    }

    // ------------------------------------------------------------ Contenu du CSV

    public function test_sans_filtre_l_export_contient_toutes_les_soumissions(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');
        $this->submission($this->physique, '2026-08-01 08:00:00');

        $response = $this->export();

        $response->assertOk();

        $rows = $this->rows($response);

        // En-tête + deux lignes + (rien de plus).
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('Référence;Formulaire;Nom', $rows[0]);
        $this->assertStringContainsString('MATHS', $rows[1]);
        $this->assertStringContainsString('PHYSIQUE', $rows[2]);
    }

    public function test_le_fichier_porte_un_bom_utf8_et_un_separateur_point_virgule(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');

        $response = $this->export();

        // Sans BOM, Excel affiche « Ã‰tudiant » au lieu de « Étudiant ».
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());

        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('.csv', (string) $response->headers->get('content-disposition'));
    }

    public function test_l_export_contient_les_colonnes_attendues_sans_adresse_ip(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00', 'pending');

        $rows = $this->rows($this->export());

        // Les en-têtes contenant un espace sont mis entre guillemets par
        // fputcsv : on compare les noms de colonnes, pas leur échappement.
        $this->assertSame(
            ['Référence', 'Formulaire', 'Nom', 'Email', 'Téléphone', 'Filière', 'Code anonyme', 'Statut', 'Date de soumission', 'Pièces jointes'],
            array_map(fn (string $column): string => trim($column, '"'), explode(';', $rows[0]))
        );
        $this->assertStringContainsString('En attente', $rows[1]);
        $this->assertStringContainsString('18/09/2026 08:00', $rows[1]);
        // L'adresse IP n'est jamais exportée.
        $this->assertStringNotContainsString('IP', $rows[0]);
        $this->assertStringNotContainsString('ip_address', $rows[0]);
    }

    // --------------------------------------------------- Les filtres sont suivis

    public function test_l_export_suit_le_filtre_formulaire(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');
        $this->submission($this->physique, '2026-09-18 08:00:00');

        $rows = $this->rows($this->export(['form' => $this->maths->id]));

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('MATHS', $rows[1]);
        $this->assertStringNotContainsString('PHYSIQUE', implode("\n", $rows));
    }

    public function test_l_export_suit_le_filtre_periode(): void
    {
        $this->submission($this->maths, '2026-09-19 09:00:00');
        $this->submission($this->maths, '2026-09-18 09:00:00');

        $rows = $this->rows($this->export(['period' => 'today']));

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('19/09/2026 09:00', $rows[1]);
    }

    public function test_l_export_suit_le_filtre_statut(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00', 'validated');
        $this->submission($this->maths, '2026-09-17 08:00:00', 'pending');

        $rows = $this->rows($this->export(['status' => 'pending']));

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Etudiant 2', $rows[1]);
    }

    public function test_l_export_suit_la_plage_avec_bornes_incluses(): void
    {
        $this->submission($this->maths, '2026-09-10 00:00:00');   // incluse
        $this->submission($this->maths, '2026-09-12 23:59:59');   // incluse
        $this->submission($this->maths, '2026-09-13 00:00:00');   // exclue

        $rows = $this->rows($this->export(['from' => '2026-09-10', 'to' => '2026-09-12']));

        $this->assertCount(3, $rows);
    }

    public function test_l_export_suit_la_recherche(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00', 'validated', ['student_name' => 'Jean Dupont']);
        $this->submission($this->maths, '2026-09-17 08:00:00', 'validated', ['student_name' => 'Marie Curie']);

        $rows = $this->rows($this->export(['search' => 'dupont']));

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Jean Dupont', $rows[1]);
        $this->assertStringNotContainsString('Marie Curie', implode("\n", $rows));
    }

    public function test_l_export_suit_les_filtres_combines(): void
    {
        $this->submission($this->maths, '2026-09-19 08:00:00', 'pending');
        $this->submission($this->maths, '2026-09-19 09:00:00', 'validated');
        $this->submission($this->physique, '2026-09-19 08:00:00', 'pending');

        $rows = $this->rows($this->export([
            'form' => $this->maths->id,
            'period' => 'today',
            'status' => 'pending',
        ]));

        $this->assertCount(2, $rows);
    }

    public function test_l_export_ignore_les_parametres_invalides(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');

        $rows = $this->rows(
            $this->get('/admin/export/submissions?form=999999&period=zzz&from=2026-13-45&status=zzz')
        );

        $this->assertCount(2, $rows);
    }

    public function test_l_export_depasse_la_limite_d_affichage_du_tableau_de_bord(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->submission($this->maths, '2026-09-01 08:00:00');
        }

        // Le tableau de bord n'affiche que 25 lignes ; l'export prend tout.
        $this->assertCount(31, $this->rows($this->export(['form' => $this->maths->id])));
    }

    // ------------------------------------------------------------------ Sécurité

    public function test_une_valeur_ressemblant_a_une_formule_est_neutralisee(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00', 'validated', [
            'student_name' => '=1+1',
            'student_email' => '@evil.test',
        ]);

        $rows = $this->rows($this->export());

        // Préfixées par une apostrophe : Excel affiche le texte au lieu de
        // l'évaluer à l'ouverture du fichier.
        $this->assertStringContainsString("'=1+1", $rows[1]);
        $this->assertStringContainsString("'@evil.test", $rows[1]);
    }

    public function test_les_valeurs_contenant_le_separateur_sont_echappees(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00', 'validated', [
            'student_name' => 'Dupont;Jean',
            'student_major' => 'Génie "Logiciel"',
        ]);

        $rows = $this->rows($this->export());

        $this->assertStringContainsString('"Dupont;Jean"', $rows[1]);
        $this->assertStringContainsString('"Génie ""Logiciel"""', $rows[1]);
        $this->assertCount(2, $rows);
    }

    // ------------------------------------------------------- Lien depuis la page

    public function test_le_tableau_de_bord_propose_un_lien_d_export_qui_reprend_les_filtres(): void
    {
        $response = $this->get('/admin?form='.$this->maths->id.'&period=7d&status=pending');

        $exportUrl = $response->viewData('exportUrl');

        $this->assertStringContainsString('/admin/export/submissions?', $exportUrl);
        $this->assertStringContainsString('form='.$this->maths->id, $exportUrl);
        $this->assertStringContainsString('period=7d', $exportUrl);
        $this->assertStringContainsString('status=pending', $exportUrl);

        $response->assertSee('Exporter en CSV');
    }

    public function test_sans_filtre_le_lien_d_export_ne_contient_aucun_parametre(): void
    {
        $response = $this->get('/admin');

        $this->assertSame(
            route('admin.export.submissions'),
            $response->viewData('exportUrl')
        );
    }
}

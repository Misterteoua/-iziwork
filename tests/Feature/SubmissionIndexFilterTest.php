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
 * Filtres de la page des soumissions d'un formulaire : période, plage de dates
 * et statut. Même lecture d'URL que le tableau de bord, mais le filtre
 * « formulaire » est absent : la page est déjà limitée à un formulaire.
 */
class SubmissionIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $form;

    private Form $autre;

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
            'title' => 'MATHS LGT',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->autre = Form::create([
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

    private function page(array $query = []): TestResponse
    {
        $url = '/admin/forms/'.$this->form->id.'/submissions';

        return $this->get($query === [] ? $url : $url.'?'.http_build_query($query));
    }

    private function submission(Form $form, string $createdAt, string $status = 'validated'): Submission
    {
        $this->counter++;

        $submission = Submission::create([
            'form_id' => $form->id,
            'student_name' => 'Etudiant '.$this->counter,
            'student_email' => 'etudiant'.$this->counter.'@test.com',
            'status' => $status,
        ]);

        $submission->created_at = Carbon::parse($createdAt);
        $submission->save();

        return $submission;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Submission> */
    private function listed(TestResponse $response)
    {
        return $response->viewData('submissions');
    }

    // ------------------------------------------------- Sans filtre : inchangé

    public function test_sans_filtre_toutes_les_soumissions_sont_affichees(): void
    {
        $this->submission($this->form, '2026-09-01 08:00:00');
        $this->submission($this->form, '2026-08-01 08:00:00');
        $this->submission($this->autre, '2026-09-01 08:00:00');

        $response = $this->page();

        $response->assertOk();
        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertCount(2, $this->listed($response));
        $this->assertSame(2, $response->viewData('totalCount'));

        // Pas de filtre actif : aucun bouton de réinitialisation.
        $response->assertDontSee('Réinitialiser les filtres');
        // Le message d'état vide par défaut n'est pas affiché non plus.
        $response->assertDontSee('Aucune soumission ne correspond à ces filtres');
    }

    public function test_la_page_ne_montre_jamais_les_soumissions_d_un_autre_formulaire(): void
    {
        $this->submission($this->form, '2026-09-18 08:00:00');
        $this->submission($this->autre, '2026-09-18 08:00:00');

        // Un filtre « form » est ignoré ici : la page reste celle de $form.
        $response = $this->page(['form' => $this->autre->id]);

        $this->assertCount(1, $this->listed($response));
        $this->assertSame([$this->form->id], $this->listed($response)->pluck('form_id')->all());
    }

    // ------------------------------------------------------------------ Période

    public function test_filtre_periode_aujourdhui(): void
    {
        $this->submission($this->form, '2026-09-19 09:00:00');
        $this->submission($this->form, '2026-09-18 09:00:00');

        $response = $this->page(['period' => 'today']);

        $response->assertOk();
        $this->assertTrue($response->viewData('isFiltered'));
        $this->assertCount(1, $this->listed($response));
        $response->assertSee('Réinitialiser');
    }

    public function test_filtre_periode_sept_jours_glissants(): void
    {
        $this->submission($this->form, '2026-09-13 00:00:00');
        $this->submission($this->form, '2026-09-12 23:59:59');

        $this->assertCount(1, $this->listed($this->page(['period' => '7d'])));
    }

    public function test_filtre_periode_mois_en_cours(): void
    {
        $this->submission($this->form, '2026-09-01 00:00:00');
        $this->submission($this->form, '2026-09-30 23:59:59');
        $this->submission($this->form, '2026-08-31 23:59:59');

        $this->assertCount(2, $this->listed($this->page(['period' => 'month'])));
    }

    // ------------------------------------------------------- Plage personnalisée

    public function test_plage_personnalisee_avec_bornes_incluses(): void
    {
        $this->submission($this->form, '2026-09-10 00:00:00');   // incluse
        $this->submission($this->form, '2026-09-12 23:59:59');   // incluse
        $this->submission($this->form, '2026-09-09 23:59:59');   // exclue
        $this->submission($this->form, '2026-09-13 00:00:00');   // exclue

        $response = $this->page(['from' => '2026-09-10', 'to' => '2026-09-12']);

        $this->assertCount(2, $this->listed($response));
        $this->assertSame(
            ['2026-09-10', '2026-09-12'],
            $this->listed($response)->pluck('created_at')->map->format('Y-m-d')->sort()->values()->all()
        );
    }

    public function test_plage_personnalisee_prioritaire_sur_la_periode(): void
    {
        $this->submission($this->form, '2026-08-01 12:00:00');

        $response = $this->page([
            'period' => 'today',
            'from' => '2026-07-31',
            'to' => '2026-08-02',
        ]);

        $this->assertCount(1, $this->listed($response));
    }

    // ------------------------------------------------------------------- Statut

    public function test_filtre_par_statut(): void
    {
        $this->submission($this->form, '2026-09-18 08:00:00', 'validated');
        $this->submission($this->form, '2026-09-17 08:00:00', 'pending');
        $this->submission($this->form, '2026-09-16 08:00:00', 'pending');

        $response = $this->page(['status' => 'pending']);

        $this->assertSame(['pending', 'pending'], $this->listed($response)->pluck('status')->all());
        $response->assertSee('value="pending" selected', false);
    }

    public function test_filtres_combines_periode_et_statut(): void
    {
        $this->submission($this->form, '2026-09-19 08:00:00', 'pending');
        $this->submission($this->form, '2026-09-19 09:00:00', 'validated');
        $this->submission($this->form, '2026-08-01 08:00:00', 'pending');

        $response = $this->page(['period' => 'today', 'status' => 'pending']);

        $this->assertCount(1, $this->listed($response));
    }

    // --------------------------------------------------- Robustesse & état vide

    public function test_parametres_invalides_sont_ignores(): void
    {
        $this->submission($this->form, '2026-09-18 08:00:00');

        $url = '/admin/forms/'.$this->form->id.'/submissions?period=zzz&from=2026-13-45&to=abc&status=zzz';

        $response = $this->get($url);

        $response->assertOk();
        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertCount(1, $this->listed($response));
    }

    public function test_filtre_sans_resultat_affiche_un_message_dedie(): void
    {
        $this->submission($this->form, '2026-09-18 08:00:00');

        $response = $this->page(['status' => 'pending']);

        $response->assertOk();
        $response->assertSee('Aucune soumission ne correspond à ces filtres');
        $response->assertSee('Réinitialiser les filtres');
        $response->assertDontSee('Les travaux déposés apparaîtront ici');
        // Le total non filtré reste affiché comme repère.
        $response->assertSee('0 sur 1 soumission');
    }

    public function test_le_telechargement_global_reste_disponible_meme_filtre_a_vide(): void
    {
        $this->submission($this->form, '2026-09-18 08:00:00');

        // Filtre qui ne renvoie rien : le bouton « Télécharger tout » porte sur
        // les dépôts réels du formulaire, il doit rester proposé.
        $response = $this->page(['period' => 'today']);

        $response->assertSee('Télécharger tout (ZIP)');
    }
}

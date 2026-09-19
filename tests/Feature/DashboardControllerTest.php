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
 * Filtres du tableau de bord : formulaire, période, plage de dates, statut.
 *
 * Toutes les dates sont figées (2026-09-19 10:00) pour que les bornes de
 * période soient prévisibles, et parce que les pièges de ce genre de filtre
 * sont toujours aux extrémités : 00 h 00 le premier jour, 23 h 59 le dernier.
 */
class DashboardControllerTest extends TestCase
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

    private function dashboard(array $query = []): TestResponse
    {
        return $this->get('/admin'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function dashboardRaw(string $queryString): TestResponse
    {
        return $this->get('/admin?'.$queryString);
    }

    /**
     * created_at n'est pas « fillable » : on l'impose donc après la création.
     */
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
        return $response->viewData('stats')['recent_submissions'];
    }

    // ------------------------------------------------------------------- Accès

    public function test_le_tableau_de_bord_reste_reserve_a_l_admin(): void
    {
        $this->flushSession();

        $this->get('/admin')->assertRedirect('/login');
    }

    // ------------------------------------------------- Sans filtre : inchangé

    public function test_sans_filtre_la_liste_reste_limitee_aux_dix_dernieres(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->submission($this->maths, '2026-09-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).' 08:00:00');
        }

        $response = $this->dashboard();

        $response->assertOk();
        $this->assertFalse($response->viewData('isFiltered'));

        $listed = $this->listed($response);

        $this->assertCount(10, $listed);
        // La plus récente en tête : le 12/09, pas le 01/09.
        $this->assertSame('2026-09-12', $listed->first()->created_at->format('Y-m-d'));
        $this->assertSame('2026-09-03', $listed->last()->created_at->format('Y-m-d'));
    }

    public function test_sans_filtre_les_cartes_de_statistiques_restent_globales(): void
    {
        $this->submission($this->maths, '2026-09-01 08:00:00');
        $this->submission($this->physique, '2026-08-01 08:00:00');

        $stats = $this->dashboard()->viewData('stats');

        $this->assertSame(2, $stats['total_submissions']);
        $this->assertSame(2, $stats['total_forms']);
        $this->assertSame(2, $stats['active_forms']);
    }

    public function test_les_formulaires_alimentent_la_liste_deroulante(): void
    {
        $response = $this->dashboard();

        $response->assertSee('MATHS');
        $response->assertSee('PHYSIQUE');
        // Aucun filtre actif : pas de bouton de réinitialisation.
        $response->assertDontSee('Réinitialiser les filtres');
    }

    // ---------------------------------------------------------- Filtre formulaire

    public function test_filtre_par_formulaire(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');
        $this->submission($this->maths, '2026-09-17 08:00:00');
        $this->submission($this->physique, '2026-09-16 08:00:00');

        $response = $this->dashboard(['form' => $this->physique->id]);

        $response->assertOk();
        $this->assertTrue($response->viewData('isFiltered'));
        $this->assertSame(1, $response->viewData('filteredCount'));
        $this->assertSame([$this->physique->id], $this->listed($response)->pluck('form_id')->all());

        // La sélection est conservée dans la liste déroulante.
        $response->assertSee('value="'.$this->physique->id.'" selected', false);
    }

    public function test_filtre_actif_affiche_jusqua_vingt_cinq_lignes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->submission($this->maths, '2026-09-01 08:00:00');
        }

        $response = $this->dashboard(['form' => $this->maths->id]);

        $this->assertCount(25, $this->listed($response));
        $this->assertSame(30, $response->viewData('filteredCount'));
        // Le total de la carte de statistiques, lui, ne bouge pas.
        $this->assertSame(30, $response->viewData('stats')['total_submissions']);
        $response->assertSee('25 affichées sur 30 correspondantes');
    }

    // ------------------------------------------------------------- Filtre période

    public function test_filtre_periode_aujourdhui(): void
    {
        $this->submission($this->maths, '2026-09-19 09:00:00');
        $this->submission($this->maths, '2026-09-18 09:00:00');

        $response = $this->dashboard(['period' => 'today']);

        $this->assertSame(1, $response->viewData('filteredCount'));
        $this->assertSame('2026-09-19', $this->listed($response)->first()->created_at->format('Y-m-d'));
    }

    public function test_filtre_periode_sept_jours_glissants(): void
    {
        // J-6 inclus (00 h 00), J-7 exclu (23 h 59).
        $this->submission($this->maths, '2026-09-13 00:00:00');
        $this->submission($this->maths, '2026-09-12 23:59:59');

        $response = $this->dashboard(['period' => '7d']);

        $this->assertSame(1, $response->viewData('filteredCount'));
        $this->assertSame('2026-09-13', $this->listed($response)->first()->created_at->format('Y-m-d'));
    }

    public function test_filtre_periode_mois_en_cours(): void
    {
        $this->submission($this->maths, '2026-09-01 00:00:00');
        $this->submission($this->maths, '2026-09-30 23:59:59');
        $this->submission($this->maths, '2026-08-31 23:59:59');
        $this->submission($this->maths, '2026-10-01 00:00:00');

        $this->assertSame(2, $this->dashboard(['period' => 'month'])->viewData('filteredCount'));
    }

    public function test_periode_toutes_les_dates_ne_filtre_rien(): void
    {
        $this->submission($this->maths, '2020-01-01 08:00:00');

        $response = $this->dashboard(['period' => 'all']);

        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertSame(1, $this->listed($response)->count());
    }

    // ------------------------------------------------------- Plage personnalisée

    public function test_plage_personnalisee_avec_bornes_incluses(): void
    {
        $this->submission($this->maths, '2026-09-10 00:00:00');   // incluse (début)
        $this->submission($this->maths, '2026-09-12 23:59:59');   // incluse (fin)
        $this->submission($this->maths, '2026-09-09 23:59:59');   // exclue
        $this->submission($this->maths, '2026-09-13 00:00:00');   // exclue

        $response = $this->dashboard(['from' => '2026-09-10', 'to' => '2026-09-12']);

        $this->assertSame(2, $response->viewData('filteredCount'));
        $this->assertSame(
            ['2026-09-10', '2026-09-12'],
            $this->listed($response)->pluck('created_at')->map->format('Y-m-d')->sort()->values()->all()
        );
    }

    public function test_plage_personnalisee_accepte_une_seule_borne(): void
    {
        $this->submission($this->maths, '2026-09-01 08:00:00');
        $this->submission($this->maths, '2026-08-01 08:00:00');

        $response = $this->dashboard(['from' => '2026-08-15']);

        $this->assertSame(1, $response->viewData('filteredCount'));
        $this->assertSame('2026-09-01', $this->listed($response)->first()->created_at->format('Y-m-d'));
    }

    public function test_plage_personnalisee_prioritaire_sur_la_periode(): void
    {
        $this->submission($this->maths, '2026-08-01 12:00:00');

        // period=today ne doit pas l'emporter sur des dates saisies à la main.
        $response = $this->dashboard([
            'period' => 'today',
            'from' => '2026-07-31',
            'to' => '2026-08-02',
        ]);

        $this->assertSame(1, $response->viewData('filteredCount'));
        $this->assertSame('2026-08-01', $this->listed($response)->first()->created_at->format('Y-m-d'));
    }

    // -------------------------------------------------------------- Filtre statut

    public function test_filtre_par_statut(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00', 'validated');
        $this->submission($this->maths, '2026-09-17 08:00:00', 'pending');
        $this->submission($this->maths, '2026-09-16 08:00:00', 'pending');

        $response = $this->dashboard(['status' => 'pending']);

        $this->assertSame(2, $response->viewData('filteredCount'));
        $this->assertSame(['pending', 'pending'], $this->listed($response)->pluck('status')->all());
    }

    public function test_filtres_combines_formulaire_periode_et_statut(): void
    {
        $this->submission($this->maths, '2026-09-19 08:00:00', 'pending');
        $this->submission($this->maths, '2026-09-19 09:00:00', 'validated');
        $this->submission($this->maths, '2026-08-01 08:00:00', 'pending');
        $this->submission($this->physique, '2026-09-19 08:00:00', 'pending');

        $response = $this->dashboard([
            'form' => $this->maths->id,
            'period' => 'today',
            'status' => 'pending',
        ]);

        $this->assertSame(1, $response->viewData('filteredCount'));
    }

    // -------------------------------------------------- Robustesse des paramètres

    public function test_parametres_invalides_sont_ignores_sans_erreur(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');

        $response = $this->dashboardRaw('form=999999&period=zzz&from=2026-13-45&to=abc&status=zzz');

        $response->assertOk();
        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertSame(1, $this->listed($response)->count());
    }

    public function test_parametres_malformes_ou_tableaux_ne_cassent_pas_la_page(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');

        $this->dashboardRaw('form[]=1&status[]=validated')->assertOk();
        $this->dashboardRaw('from=2026-02-30&to=')->assertOk();
        $this->dashboardRaw('period=&form=0')->assertOk();

        $this->assertSame(1, Submission::count());
    }

    // --------------------------------------------------------------- État vide

    public function test_filtre_sans_resultat_affiche_un_message_dedie(): void
    {
        $this->submission($this->maths, '2026-09-18 08:00:00');

        $response = $this->dashboard(['form' => $this->physique->id]);

        $response->assertOk();
        $response->assertSee('Aucune soumission ne correspond à ces filtres');
        $response->assertSee('Réinitialiser les filtres');
        // Message par défaut remplacé, pas cumulé.
        $response->assertDontSee('Les soumissions apparaîtront ici');

        // La carte « Total soumissions » reste globale.
        $this->assertSame(1, $response->viewData('stats')['total_submissions']);
    }

    public function test_sans_filtre_le_message_par_defaut_est_conserve(): void
    {
        $response = $this->dashboard();

        $response->assertSee('Aucune soumission');
        $response->assertSee('Les soumissions apparaîtront ici dès que des étudiants enverront leurs travaux.');
        $response->assertDontSee('Aucune soumission ne correspond à ces filtres');
    }
}

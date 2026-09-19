<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Filtres de la liste des évaluations : recherche, période de création, état, tri.
 *
 * Les dates sont figées (2026-09-20 10:00) pour que les bornes de période soient
 * prévisibles : c'est toujours aux extrémités que ce genre de filtre se trompe.
 */
class QuizIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

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

    private function quiz(string $title, array $attributes = []): Form
    {
        return Form::create(array_merge([
            'title' => $title,
            'token' => 'JETON-'.uniqid(),
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function listing(array $parameters = []): TestResponse
    {
        return $this->get(route('admin.quizzes.index', $parameters))->assertOk();
    }

    /** Titres affichés, dans l'ordre de la grille. */
    private function titles(TestResponse $response): array
    {
        return $response->viewData('quizzes')->pluck('title')->all();
    }

    // ------------------------------------------------------- Sans filtre

    public function test_sans_filtre_la_liste_reste_identique_a_aujourd_hui(): void
    {
        $ancienne = $this->quiz('Ancienne évaluation');
        $recente = $this->quiz('Récente évaluation');

        $ancienne->forceFill(['created_at' => Carbon::now()->subMonth()])->save();

        $response = $this->listing();

        // Tri historique : la plus récente d'abord, toutes les évaluations.
        $this->assertSame(['Récente évaluation', 'Ancienne évaluation'], $this->titles($response));
        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertSame(2, $response->viewData('total'));

        // Aucun filtre actif : pas de bouton de réinitialisation.
        $response->assertDontSee('Réinitialiser');
    }

    public function test_la_liste_ne_montre_jamais_les_depots_de_travaux(): void
    {
        $this->quiz('Examen Algorithmique');

        Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'DEPOT-LISTE',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->listing()->assertDontSee('Dépôt de rapport');
        $this->assertSame(1, $this->listing()->viewData('total'));

        // Ni avec un filtre qui, sans le cloisonnement, ramènerait le dépôt.
        $this->listing(['search' => 'rapport'])->assertDontSee('Dépôt de rapport');
    }

    // ------------------------------------------------------------ Recherche

    public function test_la_recherche_porte_sur_le_titre_et_les_consignes(): void
    {
        $this->quiz('EX QSHE S1Se1 - NAC126');
        $this->quiz('Examen Réseaux', ['description' => 'Documents interdits, calculatrice autorisée']);
        $this->quiz('Examen Physique');

        $this->assertSame(['EX QSHE S1Se1 - NAC126'], $this->titles($this->listing(['search' => 'nac126'])));
        $this->assertSame(['Examen Réseaux'], $this->titles($this->listing(['search' => 'calculatrice'])));
        $this->assertSame([], $this->titles($this->listing(['search' => 'introuvable'])));
    }

    public function test_une_recherche_sans_resultat_annonce_les_filtres_et_non_l_absence_d_evaluation(): void
    {
        $this->quiz('Examen Algorithmique');

        $response = $this->listing(['search' => 'introuvable']);

        $response->assertSee('Aucune évaluation ne correspond à ces filtres');
        $response->assertSee('Réinitialiser les filtres');
        $response->assertDontSee('Créez votre premier questionnaire');
    }

    public function test_les_jokers_sql_sont_neutralises(): void
    {
        $this->quiz('Examen Algorithmique');
        $this->quiz('Examen Réseaux');

        // « `_` » ne doit pas remplacer un caractère : « Exam_n » ne correspond à
        // rien, alors que le joker SQL aurait ramené les deux évaluations.
        $this->assertSame([], $this->titles($this->listing(['search' => 'Exam_n'])));

        // Une recherche réduite à des jokers n'est pas un filtre : la liste
        // complète s'affiche, comme sur le tableau de bord.
        $this->assertCount(2, $this->titles($this->listing(['search' => '%'])));
        $this->assertFalse($this->listing(['search' => '%_'])->viewData('isFiltered'));
    }

    // ---------------------------------------------------------------- État

    public function test_le_filtre_etat_separe_les_evaluations_ouvertes_des_fermees(): void
    {
        $this->quiz('Ouverte', ['status' => 'active']);
        $this->quiz('Fermée', ['status' => 'inactive']);

        $this->assertSame(['Ouverte'], $this->titles($this->listing(['status' => 'active'])));
        $this->assertSame(['Fermée'], $this->titles($this->listing(['status' => 'inactive'])));

        // Statut vide : aucune restriction, les deux évaluations reviennent —
        // leur ordre relève du tri, pas de ce filtre.
        $toutes = $this->titles($this->listing(['status' => '']));
        sort($toutes);

        $this->assertSame(['Fermée', 'Ouverte'], $toutes);
    }

    // -------------------------------------------------------------- Période

    public function test_la_periode_porte_sur_la_date_de_creation(): void
    {
        $aujourdhui = $this->quiz('Créée aujourd\'hui');
        $hier = $this->quiz('Créée hier');
        $vieille = $this->quiz('Créée il y a deux mois');

        $hier->forceFill(['created_at' => Carbon::now()->subDay()])->save();
        $vieille->forceFill(['created_at' => Carbon::now()->subMonths(2)])->save();

        $this->assertSame(["Créée aujourd'hui"], $this->titles($this->listing(['period' => 'today'])));
        $this->assertSame(
            ["Créée aujourd'hui", 'Créée hier'],
            $this->titles($this->listing(['period' => '30d']))
        );
        $this->assertSame(3, count($this->titles($this->listing(['period' => 'all']))));
    }

    public function test_la_bornes_de_la_periode_du_jour_sont_incluses(): void
    {
        // Minuit pile : première seconde de la journée, elle doit être retenue.
        $minuit = $this->quiz('Créée à minuit');
        $minuit->forceFill(['created_at' => Carbon::now()->startOfDay()])->save();

        // 23 h 59 la veille : exclue de « aujourd'hui ».
        $veille = $this->quiz('Créée la veille au soir');
        $veille->forceFill(['created_at' => Carbon::now()->subDay()->endOfDay()])->save();

        $this->assertSame(['Créée à minuit'], $this->titles($this->listing(['period' => 'today'])));
    }

    // ------------------------------------------------------------------ Tri

    public function test_le_tri_change_l_ordre_sans_filtrer(): void
    {
        $b = $this->quiz('Beta');
        $a = $this->quiz('Alpha');
        $b->forceFill(['created_at' => Carbon::now()->subDay()])->save();

        $this->assertSame(['Alpha', 'Beta'], $this->titles($this->listing(['sort' => 'title'])));
        $this->assertSame(['Alpha', 'Beta'], $this->titles($this->listing(['sort' => 'recent'])));
        $this->assertSame(['Beta', 'Alpha'], $this->titles($this->listing(['sort' => 'oldest'])));

        // Le tri seul n'active pas de filtre : le compteur reste global.
        $response = $this->listing(['sort' => 'title']);

        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertSame(2, $response->viewData('total'));
    }

    // ------------------------------------------------------------- Combinaisons

    public function test_les_filtres_se_combinent_et_le_compteur_le_dit(): void
    {
        $this->quiz('Examen Réseaux', ['status' => 'active']);
        $this->quiz('Examen Algorithmique', ['status' => 'active']);
        $this->quiz('Examen Physique', ['status' => 'inactive']);

        $response = $this->listing(['search' => 'examen', 'status' => 'active', 'period' => 'today']);

        $this->assertTrue($response->viewData('isFiltered'));
        $this->assertSame(2, $response->viewData('quizzes')->count());
        $this->assertSame(3, $response->viewData('total'));
        $response->assertSee('2 affichées sur 3');
        $response->assertSee('Réinitialiser');
    }

    public function test_un_seul_resultat_s_accorde_au_singulier(): void
    {
        $this->quiz('Examen Réseaux', ['status' => 'active']);
        $this->quiz('Examen Physique', ['status' => 'inactive']);

        $this->listing(['status' => 'active'])->assertSee('1 affichée sur 2');
    }

    // ------------------------------------------------------------ Robustesse

    public function test_les_parametres_invalides_sont_ignores_sans_erreur(): void
    {
        $this->quiz('Examen Algorithmique');

        $reference = $this->listing()->getContent();

        // Une URL bricolée à la main ne doit ni planter, ni vider la page.
        $response = $this->listing([
            'period' => 'zzz',
            'status' => 'inconnu',
            'sort' => 'n_importe_quoi',
            'search' => '%_',
        ]);

        $this->assertFalse($response->viewData('isFiltered'));
        $this->assertSame($reference, $response->getContent());
    }
}

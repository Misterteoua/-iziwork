<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * L'aperçu sous le champ d'énoncé : le navigateur demande au serveur le rendu de
 * ce qui est en train d'être tapé.
 *
 * Ce qui doit tenir : la mise en forme est celle de l'application (un seul
 * formateur), l'aperçu ne juge pas le texte, et il ne peut pas servir à faire
 * travailler le serveur.
 */
class QuestionPreviewTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

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

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $this->quiz = Form::create([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-APERCU',
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function preview(string $label): string
    {
        $response = $this->postJson(route('admin.quizzes.questions.preview', $this->quiz), [
            'field_label' => $label,
        ]);

        $response->assertOk();

        return (string) $response->json('html');
    }

    // --------------------------------------------------------- La mise en forme

    public function test_l_apercu_met_l_enonce_en_forme(): void
    {
        // Le même énoncé que celui de la page des questions : l'aperçu doit
        // montrer exactement ce rendu-là.
        $html = $this->preview("Cas pratiques :\n\na) premier point ; b) second point");

        $this->assertSame(
            '<p class="qt-p">Cas pratiques :</p>'
            .'<ol type="a" class="qt-list qt-alpha"><li>premier point</li><li>second point</li></ol>',
            $html
        );
    }

    public function test_un_enonce_colle_en_un_seul_bloc_est_structures(): void
    {
        $html = $this->preview('à savoir : a)le premier document ; b)le second document ; c)le troisième');

        $this->assertStringContainsString('<ol type="a" class="qt-list qt-alpha">', $html);
        $this->assertSame(3, substr_count($html, '<li>'));
    }

    public function test_l_apercu_echappe_le_texte(): void
    {
        $html = $this->preview('<script>alert(1)</script> a) un ; b) deux');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<li>deux</li>', $html);
    }

    // ------------------------------------------------- Un aperçu, pas un refus

    public function test_un_enonce_en_cours_d_ecriture_est_affiche_sans_jugement(): void
    {
        // L'aperçu sert pendant la frappe : il ne refuse rien, sinon le champ
        // se couvrirait d'erreurs avant que la question soit écrite.
        $this->assertSame('<p class="qt-p">Expliquez la saponif</p>', $this->preview('Expliquez la saponif'));
        $this->assertSame('', $this->preview('   '));
        $this->assertSame('', $this->preview(''));
    }

    public function test_un_texte_sans_limite_n_est_pas_formate_entierement(): void
    {
        // Rien n'est écrit : l'aperçu n'a pas à travailler sur ce qui ne
        // pourrait jamais être enregistré (2 000 caractères au maximum).
        $html = $this->preview(str_repeat('Question interminable. ', 900));

        $this->assertLessThan(2500, mb_strlen($html));
    }

    // ------------------------------------------------------------- Les accès

    public function test_l_apercu_est_refuse_sur_un_depot_de_travaux(): void
    {
        $deposit = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'DEPOT-APERCU',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->postJson(route('admin.quizzes.questions.preview', $deposit), [
            'field_label' => 'Une question ?',
        ])->assertNotFound();
    }

    public function test_l_apercu_exige_une_session_administrateur(): void
    {
        $this->flushSession();

        $this->post(route('admin.quizzes.questions.preview', $this->quiz), [
            'field_label' => 'Une question ?',
        ])->assertRedirect('/login');
    }

    // --------------------------------------------------------- Dans la page

    public function test_le_formulaire_propose_l_apercu_et_un_seul_script(): void
    {
        foreach (range(1, 2) as $index) {
            $this->quiz->fields()->create([
                'field_label' => 'Question '.$index,
                'field_type' => FormField::OPEN_TYPE,
                'required' => true,
                'order' => $index,
                'options' => [],
                'correct_answer' => [],
                'points' => 1,
            ]);
        }

        $html = (string) $this->get(route('admin.quizzes.show', $this->quiz))->getContent();

        // Un aperçu par formulaire : les deux questions, puis celui de l'ajout.
        $this->assertSame(3, substr_count($html, 'data-preview-url='));

        // Le fragment de formulaire est inclus trois fois, mais son script ne
        // doit être poussé qu'une fois : sinon chaque question ajouterait un
        // écouteur de plus à tous les champs.
        $this->assertSame(1, substr_count($html, "querySelectorAll('[data-question-preview]')"));

        // Sans JavaScript, l'aperçu reste caché et la saisie est identique.
        $this->assertStringContainsString('data-question-preview hidden', $html);
    }

    public function test_l_apercu_n_apparait_pas_hors_des_evaluations(): void
    {
        $deposit = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'DEPOT-SANS-APERCU',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->get(route('admin.forms.show', $deposit))
            ->assertOk()
            ->assertDontSee('data-preview-url=', false);
    }
}

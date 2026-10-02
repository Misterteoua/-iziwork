<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\QuestionImport;
use App\Support\Import\QuizTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * L'import en deux temps : un aperçu qui n'écrit rien, puis une confirmation.
 *
 * C'est aussi ce qui rend le découpage des cas pratiques acceptable : une ligne
 * qui devient plusieurs questions se voit avant d'être enregistrée, au lieu de
 * modifier le barème de l'épreuve à l'insu de l'enseignant.
 *
 * L'aperçu vit en base, et non en session : ce fichier vérifie donc autant ce
 * qu'il montre que ce qu'il survit — une déconnexion, un autre appareil, une
 * semaine d'attente.
 */
class QuizImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    /** @var array<int, string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->connect($this->admin);

        $this->quiz = Form::create([
            'title' => 'Examen Algorithmique',
            'token' => 'JETON-APERCU-IMPORT',
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaries as $path) {
            @unlink($path);
        }

        $this->temporaries = [];

        Carbon::setTestNow();

        parent::tearDown();
    }

    private function connect(AdminUser $admin): void
    {
        $this->session(['admin_user' => [
            'id' => $admin->id,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => $admin->role,
        ]]);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function upload(array $rows, string $filename = 'questions.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'iziwork-apercu');
        $this->temporaries[] = $path;

        $content = str_ends_with($filename, '.csv')
            ? implode("\n", array_map(static fn (array $row): string => implode(';', $row), $rows))
            : QuizTemplate::xlsx($rows, 'Feuille1');

        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, null, null, true);
    }

    /**
     * Dépose un fichier, et rend l'aperçu enregistré.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function preview(array $rows): QuestionImport
    {
        $this->post(route('admin.quizzes.questions.import.preview', $this->quiz), [
            'file' => $this->upload($rows),
        ])->assertOk();

        return QuestionImport::query()->latest('id')->firstOrFail();
    }

    /**
     * Le formulaire renvoie toujours l'état complet des énoncés : c'est ce que
     * fait le navigateur, et c'est ce qui permet de retoucher puis d'agir.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function reviewed(array $questions, string $action = 'confirm'): array
    {
        return [
            'action' => $action,
            'questions' => array_map(
                static fn (array $question): array => ['label' => $question['field_label']],
                array_values($questions)
            ),
        ];
    }

    /**
     * Envoie le formulaire de relecture à l'aperçu donné.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function review(QuestionImport $import, array $questions, string $action = 'confirm'): TestResponse
    {
        return $this->post(
            route('admin.quizzes.questions.import.apply', [$this->quiz, $import]),
            $this->reviewed($questions, $action)
        );
    }

    public function test_le_formulaire_d_import_envoie_vers_l_apercu(): void
    {
        $page = $this->get(route('admin.quizzes.show', $this->quiz))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.quizzes.questions.import.preview', $this->quiz), $page);
        $this->assertStringContainsString("Vérifier avant d'importer", $page);
    }

    public function test_l_apercu_montre_les_questions_sans_rien_enregistrer(): void
    {
        $this->post(route('admin.quizzes.questions.import.preview', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'Bonnes réponses', 'Points'],
                ['Capitale de la Côte d\'Ivoire ?', 'Abidjan', 'Yamoussoukro', 'B', '2'],
                ['Un langage compilé ?', 'Rust', 'PHP', 'A', '1'],
            ]),
        ])
            ->assertOk()
            ->assertSee('Aperçu de l\'import')
            ->assertSee('Capitale de la Côte d\'Ivoire ?')
            ->assertSee('Un langage compilé ?')
            ->assertSee('2 question(s) seront ajoutée(s)');

        // Rien n'est écrit tant que la confirmation n'a pas eu lieu.
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
        $this->assertSame(1, QuestionImport::count());
    }

    public function test_la_confirmation_cree_les_questions_de_l_apercu(): void
    {
        $import = $this->preview([
            ['Question', 'A', 'B', 'C', 'Bonnes réponses', 'Points'],
            ['Capitale de la Côte d\'Ivoire ?', 'Abidjan', 'Yamoussoukro', 'Bouaké', 'B', '2'],
            ['Quelles files de priorité ?', 'Tas', 'Pile', 'Fibonacci', 'A C', '3'],
        ]);

        $this->post(route('admin.quizzes.questions.import.apply', [$this->quiz, $import]), ['action' => 'confirm'])
            ->assertRedirect(route('admin.quizzes.show', $this->quiz))
            ->assertSessionHas('import_questions');

        $questions = $this->quiz->quizQuestions()->orderBy('order')->get();

        $this->assertCount(2, $questions);
        $this->assertSame('Capitale de la Côte d\'Ivoire ?', $questions[0]->field_label);
        $this->assertSame([1], $questions[0]->correctIndexes());
        $this->assertSame('checkbox', $questions[1]->field_type);
        $this->assertSame([0, 2], $questions[1]->correctIndexes());

        // L'aperçu est consommé : il ne peut pas être confirmé deux fois.
        $this->assertSame(0, QuestionImport::count());
    }

    public function test_un_cas_pratique_est_decoupe_a_l_apercu(): void
    {
        $chapeau = 'Cas pratique : l\'entreprise révise son système documentaire.';

        $this->post(route('admin.quizzes.questions.import.preview', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'Points'],
                [
                    $chapeau.' Question 1 Indiquer les documents à jour.'
                        .' Question 2 Définir la nouvelle méthode.',
                    'Ouvert',
                    '2',
                ],
            ]),
        ])
            ->assertOk()
            ->assertSee('2 question(s) seront ajoutée(s)')
            ->assertSee('Énoncés découpés')
            ->assertSee('Ligne 2 : énoncé découpé en 2 questions.')
            ->assertSee('Découpée de la ligne')
            ->assertSee('Indiquer les documents à jour.')
            ->assertDontSee('Question 1 Indiquer');

        $import = QuestionImport::query()->latest('id')->firstOrFail();

        $this->post(route('admin.quizzes.questions.import.apply', [$this->quiz, $import]), ['action' => 'confirm'])
            ->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $questions = $this->quiz->quizQuestions()->orderBy('order')->get();

        $this->assertCount(2, $questions);
        // Le contexte est recopié en tête de chaque question.
        $this->assertStringStartsWith($chapeau, $questions[0]->field_label);
        $this->assertStringStartsWith($chapeau, $questions[1]->field_label);
        $this->assertStringContainsString('Indiquer les documents à jour.', $questions[0]->field_label);
        $this->assertStringContainsString('Définir la nouvelle méthode.', $questions[1]->field_label);
    }

    public function test_le_compte_rendu_annonce_les_enonces_decoupes(): void
    {
        // L'import direct, sans aperçu, applique la même règle et la même
        // annonce : deux chemins, un seul comportement.
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'Points'],
                ['Cas : Question 1 Une. Question 2 Deux. Question 3 Trois.', 'Ouvert', '1'],
            ]),
        ]);

        $response->assertRedirect();

        $report = $response->getSession()->get('import_questions');

        $this->assertSame(3, $this->quiz->quizQuestions()->count());
        $this->assertCount(1, $report['splits']);
        $this->assertStringContainsString('découpé en 3 questions', $report['splits'][0]);
        $this->assertStringContainsString('1 énoncé découpé', $report['summary']);
    }

    // --------------------------------------- L'aperçu survit à l'interruption

    public function test_un_apercu_se_retrouve_apres_une_deconnexion(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Expliquez la saponification.', 'Ouvert', '3'],
        ]);

        // L'enseignant ferme son navigateur : la session meurt, l'aperçu reste.
        $this->flushSession();
        $this->connect($this->admin);

        $this->get(route('admin.quizzes.show', $this->quiz))
            ->assertOk()
            ->assertSee('Un import attend votre relecture')
            ->assertSee('1 question(s) analysée(s)')
            ->assertSee(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertOk()
            ->assertSee('Expliquez la saponification.');
    }

    public function test_un_apercu_se_retrouve_depuis_un_autre_appareil(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Décrivez le protocole.', 'Ouvert', '2'],
        ]);

        // Autre poste, même compte : une adresse suffit, sans retéléverser.
        $this->flushSession();
        $this->connect($this->admin);

        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertOk()
            ->assertSee('Décrivez le protocole.');
    }

    public function test_un_apercu_n_appartient_qu_a_son_auteur(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Une question.', 'Ouvert', '1'],
        ]);

        $other = AdminUser::create([
            'username' => 'autre',
            'email' => 'autre@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->flushSession();
        $this->connect($other);

        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertNotFound();

        $this->assertSame(1, QuestionImport::count());
    }

    public function test_un_nouvel_import_remplace_l_apercu_precedent(): void
    {
        $first = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Une question.', 'Ouvert', '1'],
        ]);

        $second = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Une autre question.', 'Ouvert', '1'],
        ]);

        // Un seul aperçu en attente : la page de l'évaluation ne propose pas
        // deux reprises pour le même travail.
        $this->assertSame(1, QuestionImport::count());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_abandonner_l_apercu_ne_cree_rien(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Une question.', 'Ouvert', '1'],
        ]);

        $this->delete(route('admin.quizzes.questions.import.discard', [$this->quiz, $import]))
            ->assertRedirect(route('admin.quizzes.show', $this->quiz))
            ->assertSessionHas('success');

        $this->assertSame(0, QuestionImport::count());
        $this->assertSame(0, $this->quiz->quizQuestions()->count());

        $this->get(route('admin.quizzes.show', $this->quiz))
            ->assertOk()
            ->assertDontSee('Un import attend votre relecture');
    }

    public function test_un_apercu_trop_vieux_est_ecarte(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Une question.', 'Ouvert', '1'],
        ]);

        Carbon::setTestNow(now()->addDays(QuestionImport::LIFETIME_DAYS + 1));

        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertRedirect(route('admin.quizzes.show', $this->quiz))
            ->assertSessionHas('error');

        $this->assertSame(0, QuestionImport::count());
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_la_page_n_annonce_aucun_import_quand_il_n_y_en_a_pas(): void
    {
        $this->get(route('admin.quizzes.show', $this->quiz))
            ->assertOk()
            ->assertDontSee('Un import attend votre relecture');
    }

    // ---------------------------------------------- Corriger le découpage relu

    public function test_on_peut_fusionner_deux_morceaux_d_un_enonce_decoupe(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Cas : Question 1 Une. Question 2 Deux. Question 3 Trois.', 'Ouvert', '2'],
        ]);

        $questions = $import->questionSet();

        $this->review($import, $questions, 'merge:1')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        // La fusion n'écrit rien : elle fait relire la même page.
        $this->assertSame(0, $this->quiz->quizQuestions()->count());

        $merged = $import->fresh()->questionSet();

        $this->assertCount(2, $merged);
        // Le contexte recopié n'apparaît qu'une fois : la recousure le retire.
        $this->assertSame(1, substr_count($merged[0]['field_label'], 'Cas :'));
        $this->assertStringContainsString('Une.', $merged[0]['field_label']);
        $this->assertStringContainsString('Deux.', $merged[0]['field_label']);
        // Les repères se renumérotent : il ne reste que « 1/2 » et « 2/2 ».
        $this->assertSame(2, $merged[0]['split_total']);
        $this->assertSame(1, $merged[0]['split_index']);
        $this->assertSame(2, $merged[1]['split_index']);

        $this->review($import, $merged)
            ->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $this->assertSame(2, $this->quiz->quizQuestions()->count());
    }

    public function test_on_peut_ecarter_une_question_de_l_apercu(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Cas : Question 1 Une. Question 2 Deux. Question 3 Trois.', 'Ouvert', '2'],
        ]);

        $questions = $import->questionSet();

        $this->review($import, $questions, 'remove:2')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        $remaining = $import->fresh()->questionSet();

        $this->assertCount(2, $remaining);
        $this->assertStringContainsString('Une.', $remaining[0]['field_label']);
        $this->assertStringContainsString('Deux.', $remaining[1]['field_label']);

        $this->review($import, $remaining)
            ->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $this->assertSame(2, $this->quiz->quizQuestions()->count());
    }

    public function test_la_derniere_question_peut_etre_ecartee(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Cas : Question 1 Une. Question 2 Deux. Question 3 Trois.', 'Ouvert', '2'],
        ]);

        $questions = $import->questionSet();

        $this->review($import, $questions, 'remove:0')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        $remaining = $import->fresh()->questionSet();

        $this->assertCount(2, $remaining);
        // Écarter un morceau ne renumérote pas les autres à tort.
        $this->assertStringContainsString('Deux.', $remaining[0]['field_label']);
        $this->assertStringContainsString('Trois.', $remaining[1]['field_label']);

        $this->review($import, $remaining)
            ->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $this->assertSame(2, $this->quiz->quizQuestions()->count());
    }

    public function test_deplacer_la_limite_change_l_enonce_ecrit(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Cas : Question 1 Une. Question 2 Deux.', 'Ouvert', '2'],
        ]);

        $questions = $import->questionSet();

        // L'enseignant coupe la fin de la première question : la limite bouge.
        $questions[0]['field_label'] = "Cas :\n\nUne première partie.";
        $questions[1]['field_label'] = "Cas :\n\nDeuxième partie recollée.\n\nDeux.";

        $this->review($import, $questions)
            ->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $written = $this->quiz->quizQuestions()->orderBy('order')->get();

        $this->assertCount(2, $written);
        $this->assertStringContainsString('Une première partie.', $written[0]->field_label);
        $this->assertStringContainsString('Deuxième partie recollée.', $written[1]->field_label);
    }

    public function test_un_enonce_vide_refuse_l_import_et_revient_a_l_ecran(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Cas : Question 1 Une. Question 2 Deux. Question 3 Trois.', 'Ouvert', '2'],
        ]);

        $questions = $import->questionSet();
        $questions[1]['field_label'] = '';

        $response = $this->review($import, $questions);

        $response->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));
        $response->assertSessionHas('import_question_errors');

        // Rien n'est écrit tant qu'une question ne passe pas le contrôle.
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
        $this->assertSame(1, QuestionImport::count());

        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertOk()
            ->assertSee("L'énoncé de la question est obligatoire.");
    }

    // ------------------------------------------------------------- Scinder

    public function test_on_peut_scinder_une_question_a_la_coupure(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ["Première partie.\n---\nSeconde partie.", 'Ouvert', '3'],
        ]);

        $this->assertCount(1, $import->questionSet());

        $this->review($import, $import->questionSet(), 'split:0')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        // La scission n'écrit rien : elle fait relire la même page.
        $this->assertSame(0, $this->quiz->quizQuestions()->count());

        $parts = $import->fresh()->questionSet();

        $this->assertCount(2, $parts);
        $this->assertSame('Première partie.', $parts[0]['field_label']);
        $this->assertSame('Seconde partie.', $parts[1]['field_label']);
        // La coupure est retirée du texte : elle ne se lit pas dans l'énoncé.
        $this->assertStringNotContainsString('---', $parts[0]['field_label']);

        $this->review($import, $parts)
            ->assertRedirect(route('admin.quizzes.show', $this->quiz));

        $written = $this->quiz->quizQuestions()->orderBy('order')->get();

        $this->assertCount(2, $written);
        $this->assertSame('Première partie.', $written[0]->field_label);
        $this->assertSame('Seconde partie.', $written[1]->field_label);
    }

    public function test_les_morceaux_d_une_scission_peuvent_etre_recousus(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ["Première partie.\n---\nSeconde partie.", 'Ouvert', '3'],
        ]);

        $this->review($import, $import->questionSet(), 'split:0')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        // Les deux morceaux se recousent : le bouton apparaît sur le second.
        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertOk()
            ->assertSee('Fusionner avec la précédente');

        $parts = $import->fresh()->questionSet();

        $this->review($import, $parts, 'merge:1')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        $rejoined = $import->fresh()->questionSet();

        $this->assertCount(1, $rejoined);
        $this->assertSame("Première partie.\n\nSeconde partie.", $rejoined[0]['field_label']);
        // Le repère de scission disparaît : la question est de nouveau seule.
        $this->assertArrayNotHasKey('split_total', $rejoined[0]);
    }

    public function test_scinder_sans_coupure_le_dit_a_l_ecran(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Une question sans coupure.', 'Ouvert', '2'],
        ]);

        $this->review($import, $import->questionSet(), 'split:0')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertSessionHas('import_question_errors');

        $this->get(route('admin.quizzes.questions.import.review', [$this->quiz, $import]))
            ->assertOk()
            ->assertSee('Aucune coupure trouvée');

        $this->assertCount(1, $import->fresh()->questionSet());
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_deux_questions_sans_rapport_ne_se_recousent_pas(): void
    {
        $import = $this->preview([
            ['Question', 'Type', 'Points'],
            ['Première question rédigée.', 'Ouvert', '2'],
            ['Seconde question rédigée.', 'Ouvert', '2'],
        ]);

        $this->review($import, $import->questionSet(), 'merge:1')
            ->assertRedirect(route('admin.quizzes.questions.import.review', [$this->quiz, $import]));

        // La fusion est ignorée : les deux questions restent distinctes.
        $this->assertCount(2, $import->fresh()->questionSet());
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_un_enonce_sans_repere_n_est_pas_decoupe(): void
    {
        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'Points'],
                ['Expliquez la saponification en deux ou trois lignes.', 'Ouvert', '3'],
            ]),
        ])->assertRedirect();

        $this->assertSame(1, $this->quiz->quizQuestions()->count());
    }

    public function test_un_fichier_illisible_est_refuse_des_l_apercu(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import.preview', $this->quiz), [
            'file' => $this->upload([
                ['Capitale ?', 'Abidjan', 'Yamoussoukro', 'B'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
        $this->assertSame(0, QuestionImport::count());
    }

    public function test_l_apercu_est_refuse_sur_un_depot_de_travaux(): void
    {
        $deposit = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'JETON-DEPOT-APERCU',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->post(route('admin.quizzes.questions.import.preview', $deposit), [
            'file' => $this->upload([['Question', 'A', 'B', 'Bonnes réponses'], ['Q ?', 'Un', 'Deux', 'A']]),
        ])->assertNotFound();

        $this->assertSame(0, $deposit->fields()->count());
    }

    public function test_la_vue_d_apercu_met_les_enonces_en_forme(): void
    {
        $this->post(route('admin.quizzes.questions.import.preview', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'Points'],
                ["Expliquez la démarche :\n\na) la revue des documents\nb) la codification", 'Ouvert', '2'],
            ]),
        ])
            ->assertOk()
            // L'aperçu montre les énoncés tels qu'ils apparaîtront : mis en forme.
            ->assertSee('<ol type="a" class="qt-list qt-alpha">', false)
            ->assertSee('<li>la codification</li>', false);
    }

    public function test_une_question_ouverte_avec_propositions_reste_refusee(): void
    {
        $this->post(route('admin.quizzes.questions.import.preview', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'A', 'B', 'Bonnes réponses', 'Points'],
                ['Expliquez la saponification.', 'Ouvert', '', '', '', '4'],
                ['Une ouverte avec propositions ?', 'Ouvert', 'Un', 'Deux', 'A', '2'],
            ]),
        ])
            ->assertOk()
            ->assertSee('Lignes à corriger')
            ->assertSee('question ouverte')
            ->assertSee('1 question(s) seront ajoutée(s)');
    }

    public function test_les_questions_a_propositions_ne_sont_pas_decoupees(): void
    {
        // Un énoncé à propositions qui cite « Question 1 / Question 2 » garde
        // ses propositions telles quelles : les dupliquer serait faux.
        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'Bonnes réponses', 'Points'],
                ['Question 1 Une ? Question 2 Deux ?', 'Oui', 'Non', 'A', '1'],
            ]),
        ])->assertRedirect();

        $questions = $this->quiz->quizQuestions()->get();

        $this->assertCount(1, $questions);
        $this->assertSame('radio', $questions[0]->field_type);
        $this->assertSame(['Oui', 'Non'], $questions[0]->getOptionsList());
    }
}

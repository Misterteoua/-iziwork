<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use App\Support\Import\QuizTemplate;
use App\Support\Import\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Import par fichier : questions, liste des étudiants, modèles téléchargeables
 * et liste des références à distribuer.
 *
 * Les fichiers envoyés sont de vrais fichiers, produits par le modèle de
 * l'application : c'est la seule façon de vérifier le parcours complet
 * (télécharger le modèle, le remplir, l'importer) plutôt que le seul parseur.
 */
class QuizImportTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    /** @var array<int, string> */
    private array $temporaries = [];

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
            'token' => 'JETON-IMPORT',
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

    /**
     * Fichier réellement écrit sur le disque, à envoyer tel quel.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function upload(array $rows, string $filename = 'questions.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'iziwork-upload');
        $this->temporaries[] = $path;

        $content = str_ends_with($filename, '.csv')
            ? implode("\n", array_map(static fn (array $row): string => implode(';', $row), $rows))
            : QuizTemplate::xlsx($rows, 'Feuille1');

        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function questionsPayload(array $overrides = []): array
    {
        return array_merge([
            'field_label' => 'Question importée',
            'field_type' => 'radio',
            'options' => ['Un', 'Deux', 'Trois', ''],
            'correct' => [1],
            'points' => 1,
        ], $overrides);
    }

    // ----------------------------------------------------------------- Import

    public function test_l_import_de_questions_par_excel_cree_les_questions(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
                ['Capitale de la Côte d\'Ivoire ?', 'Abidjan', 'Yamoussoukro', 'Bouaké', '', '', '', 'B', '2'],
                ['Quelles files de priorité ?', 'Tas', 'Pile', 'Fibonacci', 'Liste', '', '', 'A C', '3'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_questions');

        $questions = $this->quiz->quizQuestions()->get();

        $this->assertCount(2, $questions);
        $this->assertSame('Capitale de la Côte d\'Ivoire ?', $questions[0]->field_label);
        $this->assertSame(['Abidjan', 'Yamoussoukro', 'Bouaké'], $questions[0]->getOptionsList());
        $this->assertSame([1], $questions[0]->correctIndexes());
        $this->assertSame('2.00', $questions[0]->points);
        $this->assertSame('checkbox', $questions[1]->field_type);
        $this->assertSame([0, 2], $questions[1]->correctIndexes());
        $this->assertSame(5.0, $this->quiz->quizMaxScore());
        // L'ordre suit le fichier : la première ligne devient la première question.
        $this->assertSame([1, 2], $questions->pluck('order')->map(fn ($order) => (int) $order)->all());
    }

    public function test_l_import_d_une_question_ouverte_par_excel_la_cree(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
                ['Expliquez la saponification en deux ou trois lignes.', 'Ouvert', '', '', '', '', '', '', '', '4'],
                ['Capitale de la Côte d\'Ivoire ?', 'QCM', 'Abidjan', 'Yamoussoukro', 'Bouaké', '', '', '', 'B', '1'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_questions');

        $questions = $this->quiz->quizQuestions()->get();

        $this->assertCount(2, $questions);
        $this->assertSame(FormField::OPEN_TYPE, $questions[0]->field_type);
        $this->assertSame([], $questions[0]->getOptionsList());
        $this->assertSame([], $questions[0]->correctIndexes());
        $this->assertSame('4.00', $questions[0]->points);
        // Le guide de correction ne s'importe pas : il se rédige à la main.
        $this->assertNull($questions[0]->expected_answer);
        // Le barème global compte la question rédigée.
        $this->assertSame(5.0, $this->quiz->quizMaxScore());
        $this->assertSame('radio', $questions[1]->field_type);
    }

    public function test_une_question_ouverte_importee_est_annoncee_avec_ses_lignes_fautives(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'A', 'B', 'Bonnes réponses', 'Points'],
                ['Expliquez la saponification.', 'Ouvert', '', '', '', '4'],
                ['Une question ouverte avec des propositions ?', 'Ouvert', 'Un', 'Deux', 'A', '2'],
            ]),
        ]);

        $report = $response->getSession()->get('import_questions');

        // Une question ouverte n'a pas de propositions : la ligne qui en contient
        // est refusée et annoncée, elle n'est pas convertie en silence.
        $this->assertSame(1, $this->quiz->quizQuestions()->count());
        $this->assertSame('1 question importée · 1 erreur(s) à corriger', $report['summary']);
        $this->assertStringContainsString('question ouverte', $report['errors'][0]);
    }

    public function test_un_import_s_ajoute_aux_questions_existantes(): void
    {
        $this->quiz->fields()->create($this->questionsPayload(['order' => 1]));
        $this->quiz->fields()->create($this->questionsPayload(['order' => 2]));

        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'Bonnes réponses', 'Points'],
                ['Question ajoutée ?', 'Un', 'Deux', 'A', '1'],
            ]),
        ])->assertRedirect();

        $questions = $this->quiz->quizQuestions()->get();

        $this->assertCount(3, $questions);
        // La nouvelle question se place après les deux autres, pas à leur place.
        $this->assertSame([1, 2, 3], $questions->pluck('order')->map(fn ($order) => (int) $order)->all());
        $this->assertSame('Question ajoutée ?', $questions[2]->field_label);
    }

    public function test_les_lignes_refusees_sont_annoncees_avec_leur_numero(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'C', 'Bonnes réponses', 'Points'],
                ['Bonne ?', 'Un', 'Deux', 'Trois', 'A', '1'],
                ['Sans bonne réponse ?', 'Un', 'Deux', 'Trois', '', '1'],
            ]),
        ]);

        $report = $response->getSession()->get('import_questions');

        $this->assertSame(1, $this->quiz->quizQuestions()->count());
        $this->assertSame('1 question importée · 1 erreur(s) à corriger', $report['summary']);
        $this->assertCount(1, $report['errors']);
        $this->assertStringStartsWith('Ligne 3 :', $report['errors'][0]);
    }

    public function test_un_fichier_sans_en_tete_est_refuse_avec_un_message_qui_explique(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Capitale ?', 'Abidjan', 'Yamoussoukro', 'Bouaké', '', '', '', 'B', '2'],
            ]),
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('en-tête', $response->getSession()->get('error'));
        $this->assertStringContainsString('modèle', $response->getSession()->get('error'));
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_un_format_non_pris_en_charge_est_refuse(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iziwork-pdf');
        $this->temporaries[] = $path;
        file_put_contents($path, '%PDF-1.4');

        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => new UploadedFile($path, 'sujet.pdf', null, null, true),
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('.xlsx', $response->getSession()->get('error'));
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }

    public function test_un_import_en_csv_fonctionne_aussi(): void
    {
        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'C', 'Bonnes réponses', 'Points'],
                ['Un langage compilé ?', 'Rust', 'PHP', 'Bash', 'A', '2'],
            ], 'questions.csv'),
        ])->assertRedirect();

        $question = $this->quiz->quizQuestions()->firstOrFail();

        $this->assertSame('Un langage compilé ?', $question->field_label);
        $this->assertSame([0], $question->correctIndexes());
    }

    // --------------------------------------------------------- Liste étudiants

    public function test_l_import_de_la_liste_genere_une_reference_par_etudiant(): void
    {
        $response = $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => $this->upload([
                ['Nom', 'Email', 'Filière'],
                ['Curie Marie', 'marie@test.com', 'MPI'],
                ['Turing Alan', '', ''],
            ], 'etudiants.xlsx'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_students');

        $attempts = $this->quiz->attempts()->orderBy('id')->get();

        $this->assertCount(2, $attempts);
        $this->assertSame('Curie Marie', $attempts[0]->student_name);
        $this->assertSame('marie@test.com', $attempts[0]->student_email);
        $this->assertSame('MPI', $attempts[0]->student_major);
        $this->assertNull($attempts[1]->student_email);
        $this->assertSame(QuizAttempt::STATUS_PENDING, $attempts[0]->status);

        foreach ($attempts as $attempt) {
            $this->assertSame(10, strlen($attempt->reference));
        }

        $this->assertCount(2, $attempts->pluck('reference')->unique());
    }

    public function test_un_etudiant_deja_present_n_est_pas_importe_deux_fois(): void
    {
        $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => $this->upload([['Nom', 'Email'], ['Curie Marie', 'marie@test.com']], 'etudiants.xlsx'),
        ]);

        $response = $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => $this->upload([
                ['Nom', 'Email'],
                ['Curie Marie', 'marie@test.com'],
                ['Hopper Grace', 'grace@test.com'],
            ], 'etudiants.xlsx'),
        ]);

        $report = $response->getSession()->get('import_students');

        $this->assertSame(2, $this->quiz->attempts()->count());
        $this->assertStringContainsString('figure déjà dans la liste', implode(' ', $report['errors']));
    }

    public function test_un_etudiant_de_la_liste_entre_avec_sa_seule_reference(): void
    {
        $this->quiz->update(['status' => 'active']);
        $this->quiz->fields()->create($this->questionsPayload(['order' => 1]));

        $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => $this->upload([['Nom', 'Email'], ['Curie Marie', 'marie@test.com']], 'etudiants.xlsx'),
        ]);

        $attempt = $this->quiz->attempts()->firstOrFail();

        // Ni nom ni email ressaisis : la liste importée fait foi.
        $this->post(route('quiz.begin', $this->quiz->token), ['reference' => $attempt->reference])
            ->assertRedirect(route('quiz.question', $this->quiz->token));

        $attempt->refresh();

        $this->assertSame(QuizAttempt::STATUS_IN_PROGRESS, $attempt->status);
        $this->assertSame('Curie Marie', $attempt->student_name);
        $this->assertSame('marie@test.com', $attempt->student_email);
    }

    // ------------------------------------------------------- Énoncés très longs

    public function test_un_enonce_de_cas_pratique_de_plus_de_mille_caracteres_est_importe_entier(): void
    {
        // Un cas pratique dépasse couramment les 255 caractères d'un VARCHAR :
        // une évaluation réelle en contient de 1168 à 1513. SQLite les acceptait,
        // MySQL les refusait — l'import entier échouait sur une page blanche.
        $label = rtrim(str_repeat('Cas pratique sur la démarche qualité : ', 40));

        $this->assertGreaterThan(1000, mb_strlen($label));

        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'Bonnes réponses', 'Points'],
                [$label, 'Un audit', 'Une revue', 'A', '2'],
            ]),
        ])->assertRedirect();

        $question = $this->quiz->quizQuestions()->firstOrFail();

        // Le texte est conservé entier : pas de troncature silencieuse.
        $this->assertSame($label, $question->field_label);
        $this->assertSame(mb_strlen($label), mb_strlen($question->field_label));
    }

    public function test_un_enonce_multiligne_de_la_feuille_garde_ses_retours_a_la_ligne(): void
    {
        // Cellule Excel écrite avec Alt+Entrée, ou paragraphe Word : la structure
        // de l'énoncé vient de l'enseignant. L'import la conservait mal — le
        // texte arrivait en une seule ligne, à l'affichage de le deviner.
        $label = "Cas pratique :\nRévisez le système documentaire.\n\n1. Les documents\n2. Les procédures";

        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'Points'],
                [$label, 'Ouvert', '2'],
            ]),
        ])->assertRedirect();

        $question = $this->quiz->quizQuestions()->firstOrFail();

        $this->assertSame($label, $question->field_label);
        $this->assertStringContainsString("1. Les documents\n2. Les procédures", $question->field_label);
    }

    public function test_les_espaces_multiples_d_un_enonce_sont_toujours_normalises(): void
    {
        // Conserver les retours à la ligne ne veut pas dire garder les espaces
        // de mise en page du tableur : l'énoncé doit rester propre.
        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'Type', 'Points'],
                ['Une   question    aérée', 'Ouvert', '1'],
            ]),
        ])->assertRedirect();

        $this->assertSame('Une question aérée', $this->quiz->quizQuestions()->firstOrFail()->field_label);
    }

    public function test_la_colonne_des_enonces_accepte_un_texte_long(): void
    {
        $column = collect(Schema::getColumns('form_fields'))->firstWhere('name', 'field_label');

        // La règle applicative autorise 2000 caractères
        // (QuizQuestionData::MAX_LABEL_LENGTH) : la colonne doit pouvoir les
        // recevoir. Un VARCHAR(255) ne le pouvait pas, et SQLite ne le disait
        // pas — d'où un défaut invisible en local, fatal en production.
        $this->assertSame('text', strtolower((string) $column['type_name']));
    }

    public function test_un_enonce_de_plus_de_deux_mille_caracteres_est_refuse_avec_sa_ligne(): void
    {
        $tooLong = str_repeat('Question interminable. ', 100);

        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'Bonnes réponses', 'Points'],
                ['Une question correcte ?', 'Oui', 'Non', 'A', '1'],
                [$tooLong, 'Oui', 'Non', 'A', '1'],
            ]),
        ]);

        $response->assertRedirect();

        $report = $response->getSession()->get('import_questions');

        // La première question est créée, la seconde est refusée en le disant :
        // jamais une erreur technique, jamais un import muet.
        $this->assertSame(1, $this->quiz->quizQuestions()->count());
        $this->assertStringContainsString('Ligne 3', implode(' ', $report['errors']));
        $this->assertStringContainsString('2000 caractères', implode(' ', $report['errors']));
    }

    public function test_un_nom_de_la_liste_trop_long_est_refuse_avec_sa_ligne(): void
    {
        $tooLong = rtrim(str_repeat('Nom interminable ', 20));

        $response = $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => $this->upload([
                ['Nom', 'Email'],
                ['Curie Marie', 'marie@test.com'],
                [$tooLong, 'long@test.com'],
            ], 'etudiants.xlsx'),
        ]);

        $response->assertRedirect();

        $report = $response->getSession()->get('import_students');

        // La colonne ne prend que 255 caractères : refuser la ligne vaut mieux
        // que faire échouer tout le fichier.
        $this->assertSame(1, $this->quiz->attempts()->count());
        $this->assertStringContainsString('Ligne 3', implode(' ', $report['errors']));
        $this->assertStringContainsString('255 caractères', implode(' ', $report['errors']));
    }

    public function test_une_ecriture_refusee_par_la_base_ne_finit_pas_en_page_blanche(): void
    {
        // Bogue réel de production : MySQL refuse l'écriture, l'exception
        // traversait l'écran et laissait une page blanche sans un mot
        // (APP_DEBUG=false). On force l'incident en retirant la table que
        // l'import alimente — l'erreur est donc bien réelle, sans simulacre.
        Schema::drop('form_fields');

        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => $this->upload([
                ['Question', 'A', 'B', 'Bonnes réponses'],
                ['Une question ?', 'Un', 'Deux', 'A'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('import_questions');

        $error = (string) $response->getSession()->get('error');

        $this->assertStringContainsString("L'import n'a pas pu être enregistré", $error);
        // La transaction est annulée : le dire évite de croire à un import partiel.
        $this->assertStringContainsString("rien n'a été ajouté", $error);
    }

    // --------------------------------------------------- Modèles et références

    public function test_le_modele_des_questions_se_telecharge_et_se_relit(): void
    {
        $response = $this->get(route('admin.quizzes.questions.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'iziwork-modele');
        $this->temporaries[] = $path;
        file_put_contents($path, $response->getContent());

        $rows = XlsxReader::rows($path);

        $this->assertSame('Question', $rows[0][0]);
        $this->assertSame('Type', $rows[0][1]);
        $this->assertSame('Bonnes réponses', $rows[0][8]);
        // Le modèle est directement importable : c'est la garantie qui compte.
        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => new UploadedFile($path, 'modele.xlsx', null, null, true),
        ])->assertRedirect();

        // Trois QCM et une question ouverte : le modèle montre les deux familles.
        $this->assertSame(4, $this->quiz->quizQuestions()->count());
        $this->assertSame(1, $this->quiz->quizQuestions()->where('field_type', FormField::OPEN_TYPE)->count());
    }

    public function test_le_modele_de_la_liste_etudiants_se_telecharge(): void
    {
        $response = $this->get(route('admin.quizzes.students.template'));

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'iziwork-modele');
        $this->temporaries[] = $path;
        file_put_contents($path, $response->getContent());

        $this->assertSame('Nom', XlsxReader::rows($path)[0][0]);

        $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => new UploadedFile($path, 'modele.xlsx', null, null, true),
        ])->assertRedirect();

        $this->assertSame(3, $this->quiz->attempts()->count());
    }

    public function test_la_liste_des_references_s_exporte_en_csv_nominatif(): void
    {
        $this->post(route('admin.quizzes.students.import', $this->quiz), [
            'file' => $this->upload([
                ['Nom', 'Email', 'Filière'],
                ['Curie Marie', 'marie@test.com', 'MPI'],
            ], 'etudiants.xlsx'),
        ]);

        $attempt = $this->quiz->attempts()->firstOrFail();

        $response = $this->get(route('admin.quizzes.references.export', $this->quiz));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Référence;Nom;Email;Filière;État', $csv);
        $this->assertStringContainsString($attempt->reference, $csv);
        $this->assertStringContainsString('Curie Marie', $csv);
        $this->assertStringContainsString('Non commencée', $csv);
    }

    // ------------------------------------------------------------ Robustesse

    public function test_l_import_est_refuse_sur_un_depot_de_travaux(): void
    {
        $deposit = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'JETON-DEPOT-IMPORT',
            'status' => 'active',
            'type' => Form::TYPE_DEPOSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->post(route('admin.quizzes.questions.import', $deposit), [
            'file' => $this->upload([['Question', 'A', 'B', 'Bonnes réponses'], ['Q ?', 'Un', 'Deux', 'A']]),
        ])->assertNotFound();

        $this->post(route('admin.quizzes.students.import', $deposit), [
            'file' => $this->upload([['Nom'], ['Curie Marie']], 'etudiants.xlsx'),
        ])->assertNotFound();

        $this->assertSame(0, $deposit->fields()->count());
        $this->assertSame(0, $deposit->attempts()->count());
    }

    public function test_un_fichier_absent_est_refuse(): void
    {
        $response = $this->post(route('admin.quizzes.questions.import', $this->quiz), []);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, $this->quiz->quizQuestions()->count());
    }
}

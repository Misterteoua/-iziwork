<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\QuizAttempt;
use App\Support\Import\QuizTemplate;
use App\Support\Import\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
        $this->assertSame('Bonnes réponses', $rows[0][7]);
        // Le modèle est directement importable : c'est la garantie qui compte.
        $this->post(route('admin.quizzes.questions.import', $this->quiz), [
            'file' => new UploadedFile($path, 'modele.xlsx', null, null, true),
        ])->assertRedirect();

        $this->assertSame(3, $this->quiz->quizQuestions()->count());
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

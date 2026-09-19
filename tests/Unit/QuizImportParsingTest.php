<?php

namespace Tests\Unit;

use App\Support\Import\DocxReader;
use App\Support\Import\ImportException;
use App\Support\Import\QuestionSheet;
use App\Support\Import\QuizTemplate;
use App\Support\Import\StudentRoster;
use App\Support\Import\TabularFile;
use App\Support\Import\XlsxReader;
use Tests\TestCase;

/**
 * Lecture des fichiers d'import : .xlsx, .docx, CSV et texte.
 *
 * Les fichiers utilisés ici sont fabriqués par l'application elle-même (le
 * modèle téléchargeable, un document Word minimal) : c'est aussi la preuve que
 * le format annoncé aux enseignants est bien celui que l'import sait relire.
 */
class QuizImportParsingTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaries = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaries as $path) {
            @unlink($path);
        }

        $this->temporaries = [];

        parent::tearDown();
    }

    private function temporary(string $extension, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iziwork-test').'.'.$extension;

        file_put_contents($path, $content);
        $this->temporaries[] = $path;

        return $path;
    }

    // ------------------------------------------------------------------ Excel

    public function test_le_modele_excel_se_relit_a_l_identique(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx(QuizTemplate::questionRows(), 'Questions'));
        $rows = XlsxReader::rows($path);

        $this->assertCount(5, $rows);
        $this->assertSame('Question', $rows[0][0]);
        // La colonne « Type » est ce qui permet d'écrire une question ouverte.
        $this->assertSame('Type', $rows[0][1]);
        $this->assertSame('Bonnes réponses', $rows[0][8]);
        // Les accents et les apostrophes typographiques traversent le format.
        $this->assertStringContainsString('capitale de la Côte d\'Ivoire', $rows[1][0]);
        $this->assertSame('B', $rows[1][8]);
    }

    public function test_une_feuille_excel_sans_en_tete_est_refusee(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Un', 'Deux', 'Trois', '', '', '', '', 'B', '1'],
        ]));

        $this->expectException(ImportException::class);

        QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));
    }

    public function test_les_bonnes_reponses_en_lettres_et_en_numeros(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
            ['Capitale ?', 'Abidjan', 'Yamoussoukro', 'Bouaké', '', '', '', 'B', '2'],
            ['Files de priorité ?', 'Tas', 'Pile', 'Fibonacci', 'Liste', '', '', 'A C', '3'],
            ['Vrai ou faux ?', 'Vrai', 'Faux', '', '', '', '', '1', '0,5'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame(3, $outcome->imported);
        $this->assertSame([], $outcome->errors);

        $this->assertSame('radio', $questions[0]['field_type']);
        $this->assertSame([1], $questions[0]['correct_answer']);
        $this->assertSame(2.0, $questions[0]['points']);

        // Deux bonnes réponses désignent un choix multiple.
        $this->assertSame('checkbox', $questions[1]['field_type']);
        $this->assertSame([0, 2], $questions[1]['correct_answer']);

        // Une virgule décimale française doit passer.
        $this->assertSame(0.5, $questions[2]['points']);
    }

    public function test_une_ligne_fautive_est_signalee_avec_son_numero(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
            ['Bonne question ?', 'Un', 'Deux', 'Trois', '', '', '', 'B', '1'],
            ['Réponse impossible ?', 'Un', 'Deux', 'Trois', '', '', '', 'Z', '1'],
            ['Barème absurde ?', 'Un', 'Deux', 'Trois', '', '', '', 'A', 'beaucoup'],
            ['Sans propositions ?', 'Unique', '', '', '', '', '', 'A', '1'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame(1, $outcome->imported);
        $this->assertCount(3, $outcome->errors);
        $this->assertStringStartsWith('Ligne 3 :', $outcome->errors[0]);
        $this->assertStringContainsString('Z', $outcome->errors[0]);
        $this->assertStringContainsString('barème', $outcome->errors[1]);
        $this->assertStringContainsString('deux propositions', $outcome->errors[2]);
    }

    public function test_une_proposition_vide_ecarte_la_bonne_reponse_sans_la_decaler(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
            ['Trois propositions, dont la dernière juste ?', 'Un', 'Deux', '', 'Quatre', '', '', 'D', '1'],
        ]));

        [$questions] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        // « D » visait la quatrième colonne ; après retrait de la case vide, elle
        // occupe la troisième place — et c'est bien celle-là qui est juste.
        $this->assertSame(['Un', 'Deux', 'Quatre'], $questions[0]['options']);
        $this->assertSame([2], $questions[0]['correct_answer']);
    }

    // ------------------------------------------------------- Questions ouvertes

    public function test_une_colonne_type_declare_une_question_ouverte(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'Type', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
            ['Expliquez la saponification en deux ou trois lignes.', 'Ouvert', '', '', '', '', '', '', '', '4'],
            ['Quelle est la capitale ?', 'QCM', 'Abidjan', 'Yamoussoukro', 'Bouaké', '', '', '', 'B', '1'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame(2, $outcome->imported);
        $this->assertSame([], $outcome->errors);

        // Une question ouverte n'a ni proposition ni bonne réponse.
        $this->assertSame('textarea', $questions[0]['field_type']);
        $this->assertSame([], $questions[0]['options']);
        $this->assertSame([], $questions[0]['correct_answer']);
        $this->assertSame(4.0, $questions[0]['points']);

        // Et le QCM de la même feuille reste un QCM.
        $this->assertSame('radio', $questions[1]['field_type']);
        $this->assertSame([1], $questions[1]['correct_answer']);
    }

    public function test_une_question_ouverte_avec_des_propositions_est_refusee(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'Type', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
            ['Expliquez la saponification.', 'Ouvert', 'Saponification', 'Distillation', '', '', '', '', 'A', '4'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame([], $questions);
        $this->assertCount(1, $outcome->errors);
        $this->assertStringStartsWith('Ligne 2 :', $outcome->errors[0]);
        $this->assertStringContainsString('question ouverte', $outcome->errors[0]);
    }

    public function test_un_type_de_question_inconnu_est_signale(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'Type', 'A', 'B', 'Bonnes réponses', 'Points'],
            ['Quelque chose ?', 'Peut-être', 'Un', 'Deux', 'A', '1'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame([], $questions);
        $this->assertStringContainsString('inconnu', $outcome->errors[0]);
    }

    public function test_une_colonne_type_laissée_vide_est_signalee(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'Type', 'A', 'B', 'Bonnes réponses', 'Points'],
            ['Quelque chose ?', '', 'Un', 'Deux', 'A', '1'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame([], $questions);
        $this->assertStringContainsString('vide', $outcome->errors[0]);
    }

    public function test_un_fichier_sans_colonne_type_garde_le_comportement_d_origine(): void
    {
        // C'est la garantie de compatibilité : les fichiers préparés avant les
        // questions ouvertes ne doivent pas changer de sens.
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Question', 'A', 'B', 'C', 'Bonnes réponses', 'Points'],
            ['Choix unique ?', 'Un', 'Deux', 'Trois', 'B', '1'],
            ['Choix multiple ?', 'Un', 'Deux', 'Trois', 'A C', '2'],
            // Sans colonne « Type », une ligne sans bonne réponse reste une erreur
            // et n'est pas convertie en question ouverte.
            ['Sans bonne réponse ?', 'Un', 'Deux', 'Trois', '', '1'],
        ]));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame(2, $outcome->imported);
        $this->assertSame('radio', $questions[0]['field_type']);
        $this->assertSame('checkbox', $questions[1]['field_type']);
        $this->assertCount(1, $outcome->errors);
        $this->assertStringContainsString('aucune bonne réponse', $outcome->errors[0]);
    }

    public function test_le_modele_avec_une_question_ouverte_s_importe_sans_erreur(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx(QuizTemplate::questionRows(), 'Questions'));

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.xlsx'));

        $this->assertSame(4, $outcome->imported);
        $this->assertSame([], $outcome->errors);
        $this->assertSame(
            1,
            count(array_filter($questions, static fn (array $question): bool => $question['field_type'] === 'textarea'))
        );
    }

    // -------------------------------------------------------------------- CSV

    public function test_un_fichier_csv_ou_texte_est_lu_avec_son_separateur(): void
    {
        $content = "Question;A;B;C;Bonnes réponses;Points\n"
            ."2+2 ?;3;4;5;C;2\n"
            ."Quelle langue ?;PHP;Rust;Bash;A;1\n";

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows(
            $this->temporary('csv', $content),
            'questions.csv'
        ));

        $this->assertSame(2, $outcome->imported);
        $this->assertSame([2], $questions[0]['correct_answer']);
        $this->assertSame(2.0, $questions[0]['points']);
    }

    public function test_une_ligne_unique_avec_separateurs_est_decoupee(): void
    {
        // Ce que produit un copier-coller depuis un tableur, ou un texte écrit à
        // la main : une seule ligne, propositions séparées par des barres.
        $content = "Question | A | B | C | Bonnes réponses | Points\n"
            ."Capitale de la Côte d'Ivoire ? | Abidjan | Yamoussoukro | Bouaké | B | 1\n";

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows(
            $this->temporary('txt', $content),
            'questions.txt'
        ));

        $this->assertSame(1, $outcome->imported);
        $this->assertSame('Yamoussoukro', $questions[0]['options'][1]);
    }

    public function test_le_format_ancien_est_refuse_avec_un_message_utile(): void
    {
        $path = $this->temporary('xls', 'binaire');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessageMatches('/\.xlsx/');

        TabularFile::rows($path, 'questions.xls');
    }

    // ------------------------------------------------------------------- Word

    public function test_un_tableau_word_est_lu(): void
    {
        $path = $this->docx(
            '<w:tbl>'
            .$this->row(['Question', 'A', 'B', 'C', 'Bonnes réponses', 'Points'])
            .$this->row(['Capitale ?', 'Abidjan', 'Yamoussoukro', 'Bouaké', 'B', '2'])
            .'</w:tbl>'
        );

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.docx'));

        $this->assertSame(1, $outcome->imported);
        $this->assertSame('Yamoussoukro', $questions[0]['options'][1]);
        $this->assertSame(2.0, $questions[0]['points']);
    }

    public function test_des_paragraphes_word_separes_par_des_barres_sont_lus(): void
    {
        $path = $this->docx(
            $this->paragraph('Question | A | B | C | Bonnes réponses | Points')
            .$this->paragraph('Capitale ? | Abidjan | Yamoussoukro | Bouaké | B | 2')
        );

        [$questions, $outcome] = QuestionSheet::parse(TabularFile::rows($path, 'questions.docx'));

        $this->assertSame(1, $outcome->imported);
        $this->assertSame('radio', $questions[0]['field_type']);
    }

    public function test_un_fichier_word_invalide_est_refuse_proprement(): void
    {
        // Archive valide, mais sans le document Word attendu : c'est bien le
        // contenu qui doit être refusé, pas l'ouverture du fichier.
        $zip = new \ZipArchive;
        $path = $this->temporary('docx', '');
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/other.xml', '<x/>');
        $zip->close();

        $this->expectException(ImportException::class);

        DocxReader::rows($path);
    }

    // ------------------------------------------------------ Liste des étudiants

    public function test_une_liste_avec_en_tete_est_lue(): void
    {
        $path = $this->temporary('xlsx', QuizTemplate::xlsx([
            ['Nom', 'Email', 'Filière'],
            ['Curie Marie', 'marie@test.com', 'MPI'],
            ['Turing Alan', '', 'ISI'],
        ]));

        [$students, $outcome] = StudentRoster::parse(TabularFile::rows($path, 'etudiants.xlsx'));

        $this->assertSame(2, $outcome->imported);
        $this->assertSame('Curie Marie', $students[0]['name']);
        $this->assertSame('marie@test.com', $students[0]['email']);
        $this->assertSame('MPI', $students[0]['major']);
        // Email absent : la ligne reste exploitable, la référence sera générée.
        $this->assertNull($students[1]['email']);
    }

    public function test_une_liste_sans_en_tete_est_lue_dans_l_ordre(): void
    {
        [$students, $outcome] = StudentRoster::parse([
            ['Kouassi Awa', 'awa@test.com', 'LGT'],
            ['Dupont Jean'],
        ]);

        $this->assertSame(2, $outcome->imported);
        $this->assertSame('Kouassi Awa', $students[0]['name']);
        $this->assertSame('awa@test.com', $students[0]['email']);
        $this->assertSame('Dupont Jean', $students[1]['name']);
    }

    public function test_un_email_invalide_et_un_doublon_sont_signales(): void
    {
        [$students, $outcome] = StudentRoster::parse([
            ['Nom', 'Email'],
            ['Curie Marie', 'pas-un-email'],
            ['Turing Alan', 'alan@test.com'],
            ['Turing Alan', 'alan@test.com'],
            ['Hopper Grace', 'grace@test.com'],
        ]);

        $this->assertSame(2, $outcome->imported);
        $this->assertCount(2, $outcome->errors);
        $this->assertStringContainsString('n\'est pas valide', $outcome->errors[0]);
        $this->assertStringContainsString('déjà', $outcome->errors[1]);
    }

    public function test_une_liste_sans_nom_est_refusee(): void
    {
        $this->expectException(ImportException::class);

        StudentRoster::parse([['', ''], ['', '']]);
    }

    /**
     * Document Word minimal : une seule partie suffit à ce que lit l'application.
     */
    private function docx(string $body): string
    {
        $zip = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'iziwork-docx').'.docx';
        $this->temporaries[] = $path;
        // ZipArchive n'ouvre pas un chemin inexistant en écriture seule : on crée
        // le fichier vide d'abord.
        file_put_contents($path, '');
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body.'</w:body></w:document>'
        );
        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function row(array $cells): string
    {
        $xml = '<w:tr>';

        foreach ($cells as $cell) {
            $xml .= '<w:tc>'.$this->paragraph($cell).'</w:tc>';
        }

        return $xml.'</w:tr>';
    }

    private function paragraph(string $text): string
    {
        return '<w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
    }
}

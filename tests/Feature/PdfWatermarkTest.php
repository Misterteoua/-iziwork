<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Models\Submission;
use App\Support\PdfWatermark;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Filigrane et numéro de document des PDF.
 *
 * Ce fichier vérifie les deux protections anti-falsification :
 *   1. un filigrane incliné et translucide, réellement tracé dans le flux du
 *      PDF (et non posé en HTML — dompdf n'applique pas la rotation CSS) ;
 *   2. un numéro de document stable et infalsifiable, dérivé de la clé de
 *      l'application.
 *
 * Le contrôle ne se fait pas sur la vue, mais sur le **PDF produit** : c'est le
 * document que l'étudiant reçoit, et c'est donc lui qu'il faut inspecter.
 */
class PdfWatermarkTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    // ------------------------------------------------------------ Le numéro

    public function test_le_numero_de_document_est_stable_et_lisible(): void
    {
        $a = PdfWatermark::documentId('quiz-attempt', 'ABCDEF1234');
        $b = PdfWatermark::documentId('quiz-attempt', 'ABCDEF1234');

        // Stable : deux appels donnent le même numéro, sans stockage.
        $this->assertSame($a, $b);

        // Format lisible : préfixe, puis des groupes hexadécimaux de quatre.
        $this->assertMatchesRegularExpression('/^DOC(-[0-9A-F]{4}){3}$/', $a);
    }

    public function test_le_numero_depend_de_la_cle_et_de_l_objet(): void
    {
        // Deux objets différents : deux numéros différents.
        $this->assertNotSame(
            PdfWatermark::documentId('quiz-attempt', 'AAAA'),
            PdfWatermark::documentId('quiz-attempt', 'BBBB')
        );

        // Deux natures de document pour le même identifiant : deux numéros
        // différents — un numéro d'évaluation ne peut pas servir pour un dépôt.
        $this->assertNotSame(
            PdfWatermark::documentId('quiz-attempt', 'AAAA'),
            PdfWatermark::documentId('submission', 'AAAA')
        );

        // Changer la clé de l'application change le numéro : c'est ce qui rend
        // la fabrication impossible sans le secret du serveur.
        $original = config('app.key');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $avecCle = PdfWatermark::documentId('quiz-attempt', 'AAAA');

        config(['app.key' => $original]);
        $sansAutreCle = PdfWatermark::documentId('quiz-attempt', 'AAAA');

        $this->assertNotSame($avecCle, $sansAutreCle);
    }

    public function test_une_cle_absente_ne_casse_pas_la_generation(): void
    {
        // Une installation mal configurée ne doit pas faire échouer la
        // génération d'un document : on retombe sur une valeur fixe.
        $original = config('app.key');
        config(['app.key' => '']);

        try {
            $this->assertMatchesRegularExpression(
                '/^DOC(-[0-9A-F]{4}){3}$/',
                PdfWatermark::documentId('quiz-attempt', 'AAAA')
            );
        } finally {
            config(['app.key' => $original]);
        }
    }

    // ---------------------------------------------------- Filigrane dans le PDF

    /**
     * Extrait et décompresse les flux de contenu d'un PDF.
     *
     * Découpage par `strpos`, et non par expression régulière : un motif
     * non-gourmand sur un fichier binaire peut dépasser la pile du moteur PCRE
     * (JIT) et rendre `false` sans prévenir sous charge — l'extraction serait
     * alors vide, et le test échouerait au hasard. Un parcours linéaire ne
     * dépend ni de la taille du document, ni de la mémoire disponible.
     */
    private function contentStreams(string $pdf): string
    {
        $out = '';
        $offset = 0;

        while (($start = strpos($pdf, 'stream', $offset)) !== false) {
            $dataStart = $start + strlen('stream');

            // Un flux est annoncé par « stream » suivi d'un saut de ligne.
            if (substr($pdf, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (in_array(substr($pdf, $dataStart, 1), ["\r", "\n"], true)) {
                $dataStart++;
            } else {
                // « stream » au milieu d'un autre mot (en-tête, métadonnée).
                $offset = $dataStart;

                continue;
            }

            $end = strpos($pdf, 'endstream', $dataStart);

            if ($end === false) {
                break;
            }

            $out .= $this->decodeStream(rtrim(substr($pdf, $dataStart, $end - $dataStart), "\r\n"));

            // Reprendre après « endstream », qui contient « stream ».
            $offset = $end + strlen('endstream');
        }

        return $out;
    }

    /** Décompresse un flux de PDF, quel que soit son encodage ; à défaut, brut. */
    private function decodeStream(string $stream): string
    {
        $decoded = @gzuncompress($stream);

        if ($decoded !== false) {
            return $decoded;
        }

        $inflated = @gzinflate($stream);

        if ($inflated !== false) {
            return $inflated;
        }

        // Flux non compressé : le texte reste lisible, on le garde tel quel.
        return $stream;
    }

    private function attempt(): QuizAttempt
    {
        $quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-WM-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => false],
            'created_by' => $this->admin->id,
        ]);

        $quiz->fields()->create([
            'field_label' => 'Expliquez la saponification.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => 1,
            'options' => [],
            'correct_answer' => [],
            'points' => 4,
        ]);

        $this->post(route('quiz.begin', $quiz->token), ['student_name' => 'Curie Marie']);
        $this->post(route('quiz.answer', $quiz->token), [
            'question_id' => $quiz->quizQuestions()->firstOrFail()->id,
            'answer_text' => 'Huile et soude donnent du savon.',
        ]);
        $this->post(route('quiz.submit', $quiz->token));

        return $quiz->attempts()->firstOrFail();
    }

    public function test_le_recapitulatif_d_evaluation_porte_le_filigrane_et_le_numero(): void
    {
        $attempt = $this->attempt();

        $response = $this->get(route('quiz.recap.pdf', [$attempt->form->token, $attempt->reference]));
        $response->assertOk();

        $pdf = (string) $response->getContent();
        $expectedId = PdfWatermark::documentId('quiz-attempt', $attempt->reference);

        // Le numéro est présent, en clair, dans le contenu du PDF.
        $this->assertStringContainsString($expectedId, $this->contentStreams($pdf));

        // Et il est incliné : la matrice de rotation d'un angle de 45° est
        // « 0.707 -0.707 0.707 0.707 ». C'est la preuve que le filigrane n'est
        // pas un simple texte droit posé dans une page.
        $this->assertStringContainsString('0.707 -0.707 0.707 0.707', $this->contentStreams($pdf));

        // La transparence est bien posée (ExtGState /ca), sinon le filigrane
        // masquerait le contenu.
        $this->assertMatchesRegularExpression('/\/ca 0\.0[0-9]/', $pdf);
    }

    public function test_le_filigrane_est_repete_sur_chaque_page(): void
    {
        // Un document de deux pages : le filigrane doit apparaître sur les deux,
        // pas seulement sur la première.
        $quiz = Form::create([
            'title' => 'Examen long',
            'token' => 'JETON-WM-LONG-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => false],
            'created_by' => $this->admin->id,
        ]);

        // Beaucoup de questions : le document dépasse une page.
        for ($i = 1; $i <= 40; $i++) {
            $quiz->fields()->create([
                'field_label' => "Question numéro $i avec un intitulé suffisamment long pour occuper de la place.",
                'field_type' => FormField::OPEN_TYPE,
                'required' => true,
                'order' => $i,
                'options' => [],
                'correct_answer' => [],
                'points' => 1,
            ]);
        }

        $this->post(route('quiz.begin', $quiz->token), ['student_name' => 'Curie Marie']);
        $this->post(route('quiz.submit', $quiz->token));

        $attempt = $quiz->attempts()->firstOrFail();

        $response = $this->get(route('quiz.recap.pdf', [$quiz->token, $attempt->reference]));
        $response->assertOk();

        $streams = $this->contentStreams((string) $response->getContent());

        // Autant d'occurrences de la matrice de rotation que de pages.
        $this->assertGreaterThanOrEqual(2, substr_count($streams, '0.707 -0.707 0.707 0.707'));
    }

    public function test_le_recapitulatif_de_depot_porte_le_filigrane_et_le_numero(): void
    {
        $form = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'JETON-WM-DEPOT-'.uniqid(),
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $submission = Submission::create([
            'form_id' => $form->id,
            'student_name' => 'John Doe',
            'status' => 'validated',
        ]);

        $response = $this->get("/s/{$form->token}/recap/{$submission->receipt_token}/pdf");
        $response->assertOk();

        $pdf = (string) $response->getContent();
        $expectedId = PdfWatermark::documentId('submission', $submission->receipt_token);

        $this->assertStringContainsString($expectedId, $this->contentStreams($pdf));
        $this->assertStringContainsString('0.707 -0.707 0.707 0.707', $this->contentStreams($pdf));
    }

    public function test_la_fiche_de_mission_porte_le_filigrane_et_le_numero(): void
    {
        $quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-WM-MISSION-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['duration_minutes' => 30],
            'created_by' => $this->admin->id,
        ]);

        $this->session(['admin_user' => [
            'id' => $this->admin->id,
            'username' => $this->admin->username,
            'email' => $this->admin->email,
            'role' => $this->admin->role,
        ]]);

        $this->post(route('admin.quizzes.graders.store', $quiz), [
            'name' => 'Awa Diallo',
            'email' => 'awa@correcteur.test',
            'days' => 3,
        ])->assertSessionHasNoErrors();

        $grader = $quiz->graders()->firstOrFail();

        $response = $this->get(route('admin.quizzes.graders.mission', [$quiz, $grader]));
        $response->assertOk();

        $pdf = (string) $response->getContent();
        $expectedId = PdfWatermark::documentId('grader', $grader->reference);

        $this->assertStringContainsString($expectedId, $this->contentStreams($pdf));
        $this->assertStringContainsString('0.707 -0.707 0.707 0.707', $this->contentStreams($pdf));
    }

    public function test_le_pied_de_page_affiche_le_numero_de_document(): void
    {
        // Le numéro figure aussi en clair dans le document, pas seulement en
        // filigrane : c'est ce qui permet de le lire et de le recopier.
        $attempt = $this->attempt();
        $expectedId = PdfWatermark::documentId('quiz-attempt', $attempt->reference);

        $html = view('student.quiz.recap-pdf', [
            'quiz' => $attempt->form,
            'attempt' => $attempt->load('answers'),
            'showScore' => true,
            'revealsCorrection' => true,
            'revealMoment' => null,
            'pending' => 0,
            'followLink' => ShortLink::forAttempt($attempt->form, $attempt),
            'followQr' => QrPng::dataUri('https://exemple.test/l/Ab12Cd34'),
            'documentId' => $expectedId,
        ])->render();

        $this->assertStringContainsString('Numéro de document', $html);
        $this->assertStringContainsString($expectedId, $html);
    }

    /**
     * Le récapitulatif d'une réponse rendue uniquement par un document.
     *
     * Le document est produit par dompdf, via la route réelle, puis inspecté :
     * le filigrane incliné et le numéro de document doivent être là comme pour
     * n'importe quelle copie — c'est la réponse de l'étudiant, et c'est cette
     * copie-là que l'établissement peut avoir à authentifier.
     */
    public function test_le_recapitulatif_d_une_reponse_par_document_porte_le_filigrane_et_le_numero(): void
    {
        Storage::fake('local');

        $quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-WM-DOC-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true, 'proctoring' => false],
            'created_by' => $this->admin->id,
        ]);

        $question = $quiz->fields()->create([
            'field_label' => 'Rédigez la procédure de traitement des réclamations.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => 1,
            'options' => [],
            'correct_answer' => [],
            'points' => 5,
        ]);

        // Un document seul : aucun texte saisi.
        $this->post(route('quiz.begin', $quiz->token), ['student_name' => 'Curie Marie']);
        $this->post(route('quiz.answer', $quiz->token), [
            'question_id' => $question->id,
            'answer_text' => null,
            'attachments' => [UploadedFile::fake()->create('Listing_Ex_180926.pdf', 96, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $this->post(route('quiz.submit', $quiz->token));

        $attempt = $quiz->attempts()->firstOrFail();

        $response = $this->get(route('quiz.recap.pdf', [$quiz->token, $attempt->reference]));
        $response->assertOk();

        $pdf = (string) $response->getContent();

        // Un vrai PDF dompdf, produit de bout en bout.
        $this->assertStringStartsWith('%PDF-', $pdf);

        $streams = $this->contentStreams($pdf);
        $expectedId = PdfWatermark::documentId('quiz-attempt', $attempt->reference);

        // Le numéro de document, en clair dans le flux (eau + pied de page).
        $this->assertStringContainsString($expectedId, $streams);

        // Le filigrane est incliné (matrice de rotation à 45°)...
        $this->assertStringContainsString('0.707 -0.707 0.707 0.707', $streams);

        // ... et translucide (ExtGState /ca), sinon il masquerait le contenu.
        $this->assertMatchesRegularExpression('/\/ca 0\.0[0-9]/', $pdf);
    }

    /**
     * Le numéro doit être trouvé à **chaque** génération, pas une fois sur deux.
     *
     * Le test de dépôt échouait autrefois de façon intermittente : le numéro
     * était bien dans le PDF, mais l'extraction des flux pouvait ressortir vide.
     * Ce test régénère le document plusieurs fois d'affilée et l'exige à chaque
     * tour, pour qu'une régression du décodage se voie immédiatement.
     */
    public function test_le_numero_est_trouve_a_chaque_generation_du_recapitulatif(): void
    {
        $form = Form::create([
            'title' => 'Dépôt de rapport',
            'token' => 'JETON-WM-BOUCLE-'.uniqid(),
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $submission = Submission::create([
            'form_id' => $form->id,
            'student_name' => 'John Doe',
            'status' => 'validated',
        ]);

        $expectedId = PdfWatermark::documentId('submission', $submission->receipt_token);

        for ($run = 1; $run <= 6; $run++) {
            $response = $this->get("/s/{$form->token}/recap/{$submission->receipt_token}/pdf");
            $response->assertOk();

            $streams = $this->contentStreams((string) $response->getContent());

            $this->assertNotSame('', $streams, "Extraction vide au tour {$run}.");
            $this->assertStringContainsString($expectedId, $streams, "Numéro de document absent au tour {$run}.");
        }
    }

    /**
     * L'extraction ne dépend ni de la compression, ni d'un détail du PDF.
     *
     * Un flux gzip, un flux « deflate » brut et un flux non compressé doivent
     * tous être lus : le numéro d'un document ne doit pas dépendre de la façon
     * dont dompdf a choisi d'écrire ses flux.
     */
    public function test_l_extraction_lit_tous_les_encodages_de_flux(): void
    {
        $lf = chr(10);
        $payload = 'DOC-AAAA-BBBB-CCCC';

        $pdf = '%PDF-1.7'.$lf
            .'1 0 obj'.$lf.'<< /Length 0 >>'.$lf.'stream'.$lf.gzcompress($payload).$lf.'endstream'.$lf.'endobj'.$lf
            .'2 0 obj'.$lf.'<< /Length 0 >>'.$lf.'stream'.$lf.gzdeflate($payload).$lf.'endstream'.$lf.'endobj'.$lf
            .'3 0 obj'.$lf.'<< /Length 0 >>'.$lf.'stream'.$lf.$payload.$lf.'endstream'.$lf.'endobj'.$lf;

        $streams = $this->contentStreams($pdf);

        $this->assertSame(3, substr_count($streams, $payload));
    }
}

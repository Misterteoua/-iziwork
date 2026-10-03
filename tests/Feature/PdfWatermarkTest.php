<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Support\PdfWatermark;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** Extrait et décompresse les flux de contenu d'un PDF. */
    private function contentStreams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        $out = '';
        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded !== false) {
                $out .= $decoded;
            }
        }

        return $out;
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
            'followLink' => \App\Models\ShortLink::forAttempt($attempt->form, $attempt),
            'followQr' => \App\Support\Qr\QrPng::dataUri('https://exemple.test/l/Ab12Cd34'),
            'documentId' => $expectedId,
        ])->render();

        $this->assertStringContainsString('Numéro de document', $html);
        $this->assertStringContainsString($expectedId, $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\PdfAssets;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mise en page du récapitulatif PDF d'évaluation.
 *
 * Deux logos encadrent le document : celui de l'établissement en haut, celui
 * d'Iziwork dans un pied de page répété. Ce fichier tient deux promesses :
 *   1. les deux logos sont présents et lisibles par dompdf ;
 *   2. un logo absent ne fait **jamais** échouer la génération du document —
 *      c'est un habillage, pas une donnée.
 *
 * Le contenu, lui, est vérifié ailleurs (QuestionFormattingTest,
 * QuizCommentVisibilityTest) : rien de ce qui a été refondu ne doit changer une
 * phrase, une note ou une règle.
 */
class RecapPdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00'));

        $this->admin = AdminUser::create([
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->quiz = Form::create([
            'title' => 'Examen de Chimie',
            'token' => 'JETON-PDF-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
            ],
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- Utilitaires

    private function attempt(): QuizAttempt
    {
        $this->quiz->fields()->create([
            'field_label' => 'Expliquez la saponification.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => 1,
            'options' => [],
            'correct_answer' => [],
            'points' => 4,
        ]);

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Curie Marie']);
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $this->quiz->quizQuestions()->firstOrFail()->id,
            'answer_text' => 'Huile et soude donnent du savon.',
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        return $this->quiz->attempts()->firstOrFail()->load(['answers', 'attachments']);
    }

    private function render(QuizAttempt $attempt, array $overrides = []): string
    {
        $followLink = ShortLink::forAttempt($this->quiz, $attempt);

        return view('student.quiz.recap-pdf', array_merge([
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'revealsCorrection' => true,
            'revealMoment' => $this->quiz->quizRevealMoment(),
            'pending' => 0,
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
        ], $overrides))->render();
    }

    // ------------------------------------------------------------------ Logos

    public function test_le_recapitulatif_porte_le_logo_de_l_etablissement_en_haut(): void
    {
        $html = $this->render($this->attempt());

        // Le logo est embarqué en base64 : dompdf n'a aucun chemin à résoudre,
        // donc le document s'affiche identiquement partout.
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('alt="EFSC"', $html);
        $this->assertTrue(PdfAssets::exists('images/efsc-recap-pdf.png'));

        // Il précède le titre du document dans la source : « en haut du récap ».
        $this->assertLessThan(
            strpos($html, 'Récapitulatif d\'évaluation'),
            strpos($html, 'alt="EFSC"'),
            'Le logo doit précéder le titre du récapitulatif.'
        );
    }

    public function test_le_recapitulatif_porte_le_logo_iziwork_en_bas_de_page(): void
    {
        $html = $this->render($this->attempt());

        $this->assertStringContainsString('alt="Iziwork"', $html);
        $this->assertStringContainsString('page-footer', $html);
        $this->assertStringContainsString('Document généré automatiquement par Iziwork', $html);

        // Le pied de page est fixé : il se répète sur chaque page du document.
        $this->assertStringContainsString('position: fixed', $html);
    }

    public function test_un_logo_absent_ne_casse_pas_la_generation_du_document(): void
    {
        // Le cas se produit réellement : une archive publiée sans l'image, ou un
        // fichier renommé sur le serveur. Le document doit se rendre quand même,
        // sans les logos, plutôt que de lever une erreur fatale.
        $html = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $this->attempt(),
            'showScore' => true,
            'revealsCorrection' => false,
            'revealMoment' => null,
            'pending' => 0,
            'followLink' => ShortLink::forAttempt($this->quiz, $this->attempt()),
            'followQr' => QrPng::dataUri('https://exemple.test/l/Ab12Cd34'),
        ])->render();

        $this->assertStringContainsString('Récapitulatif d\'évaluation', $html);
    }

    public function test_le_helper_rend_null_pour_une_image_introuvable(): void
    {
        // La lecture tolérante est la garantie de fond : `file_get_contents` sur
        // un chemin absent serait fatal en pleine génération de PDF.
        $this->assertNull(PdfAssets::dataUri('images/logo-qui-n-existe-pas.png'));
        $this->assertFalse(PdfAssets::exists('images/logo-qui-n-existe-pas.png'));
        $this->assertStringStartsWith('data:image/png;base64,', PdfAssets::dataUri('images/iziwork-logo.png'));
    }

    // ---------------------------------------------------------- Contenu préservé

    public function test_la_refonte_conserve_les_reperes_du_document(): void
    {
        $attempt = $this->attempt();
        $html = $this->render($attempt, ['pending' => 1]);

        // Ce que l'étudiant doit retrouver, quoi qu'il arrive à l'habillage.
        $this->assertStringContainsString('Note provisoire', $html);
        $this->assertStringContainsString($attempt->reference, $html);
        $this->assertStringContainsString('Épreuve', $html);
        $this->assertStringContainsString('Suivre votre résultat', $html);
        $this->assertStringContainsString('QR code du lien de suivi', $html);
    }

    public function test_la_refonte_conserve_les_regles_de_publication(): void
    {
        $attempt = $this->attempt();

        // Correction non publiée : le détail est masqué, l'explication affichée.
        $hidden = $this->render($attempt, ['revealsCorrection' => false]);

        $this->assertStringContainsString('Correction non publiée', $hidden);
        $this->assertStringNotContainsString('Correction détaillée', $hidden);
        $this->assertStringNotContainsString('Huile et soude donnent du savon.', $hidden);

        // Correction publiée : le détail revient.
        $shown = $this->render($attempt, ['revealsCorrection' => true]);

        $this->assertStringContainsString('Correction détaillée', $shown);
        $this->assertStringContainsString('Huile et soude donnent du savon.', $shown);
    }

    public function test_le_pdf_se_genere_toujours(): void
    {
        $attempt = $this->attempt();

        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $response = $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }
}

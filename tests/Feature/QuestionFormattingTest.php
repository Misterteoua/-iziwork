<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Un énoncé doit être lisible partout où il apparaît : page des questions,
 * carte du candidat, écran de correction et récapitulatif PDF.
 *
 * Ces tests portent sur l'affichage réel des vues — la mise en forme étant un
 * rendu, c'est le HTML produit qui compte, pas le texte stocké.
 */
class QuestionFormattingTest extends TestCase
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
            'token' => 'JETON-FORMAT',
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => ['duration_minutes' => 30, 'show_score' => true],
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * L'énoncé de la capture : un cas pratique importé d'un fichier, arrivé en
     * une seule ligne, énumérations comprises.
     */
    private function enonce(): string
    {
        return 'Cas pratiques : au cours de ce séminaire, des faiblesses ont été relevées au niveau du système '
            .'documentaire, à savoir : a)L\'utilisation de documents non à jour ; b)La codification incohérente ; '
            .'c)La politique intégrée ; d)Les logigrammes non conformes ; Question 5 Indiquer et définir la '
            .'nouvelle méthode.';
    }

    private function question(string $label, string $type = FormField::OPEN_TYPE): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => $label,
            'field_type' => $type,
            'required' => true,
            'order' => (int) $this->quiz->fields()->max('order') + 1,
            'options' => $type === FormField::OPEN_TYPE ? [] : ['Un', 'Deux'],
            'correct_answer' => $type === FormField::OPEN_TYPE ? [] : [0],
            'points' => 2,
        ]);
    }

    // ---------------------------------------------------- Page des questions

    public function test_la_page_des_questions_met_l_enonce_en_forme(): void
    {
        $this->question($this->enonce());

        $response = $this->get(route('admin.quizzes.show', $this->quiz));

        $response->assertOk();
        $response->assertSee('<ol type="a" class="qt-list qt-alpha">', false);
        $response->assertSee('<li>L&#039;utilisation de documents non à jour</li>', false);
        $response->assertSee('<li>La codification incohérente</li>', false);

        // Le numéro de la question reste un repère, séparé de l'énoncé.
        $response->assertSee('<span class="qt-index">1.</span> Cas pratiques :', false);
    }

    public function test_un_enonce_piege_n_injecte_pas_de_html(): void
    {
        $this->question('<script>alert(1)</script> a) premier point ; b) second point');

        $response = $this->get(route('admin.quizzes.show', $this->quiz));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertSee('<li>second point</li>', false);
    }

    // ---------------------------------------------------------- Côté candidat

    public function test_la_carte_du_candidat_met_l_enonce_en_forme(): void
    {
        $question = $this->question($this->enonce());

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Jean']);

        $response = $this->get(route('quiz.question', $this->quiz->token));

        $response->assertOk();
        $response->assertSee('<ol type="a" class="qt-list qt-alpha">', false);
        $response->assertSee('<li>Les logigrammes non conformes</li>', false);
        $response->assertSee('Question 5 Indiquer et définir la nouvelle méthode.', false);

        $this->assertSame(1, $this->quiz->fields()->count());
    }

    // ------------------------------------------------------------- Correction

    public function test_l_ecran_de_correction_met_l_enonce_en_forme(): void
    {
        $question = $this->question($this->enonce());

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'answer_text' => 'Une méthode de gestion documentaire.',
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail();

        $response = $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]));

        $response->assertOk();
        $response->assertSee('<ol type="a" class="qt-list qt-alpha">', false);
        $response->assertSee('<li>La politique intégrée</li>', false);
    }

    // -------------------------------------------------------- Récapitulatif

    public function test_le_recapitulatif_pdf_met_l_enonce_en_forme(): void
    {
        $question = $this->question($this->enonce());

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Jean']);
        $this->post(route('quiz.answer', $this->quiz->token), [
            'question_id' => $question->id,
            'answer_text' => 'Une méthode de gestion documentaire.',
        ]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attempt = $this->quiz->attempts()->firstOrFail()->load('answers');
        $followLink = ShortLink::forAttempt($this->quiz, $attempt);

        // Le binaire du PDF n'est pas lisible : c'est la vue du document qui
        // porte la mise en forme, et c'est elle qu'on vérifie.
        $html = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'revealsCorrection' => true,
            'revealMoment' => $this->quiz->quizRevealMoment(),
            'pending' => 0,
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
        ])->render();

        $this->assertStringContainsString('<ol type="a" class="qt-list qt-alpha">', $html);
        $this->assertStringContainsString('<span class="qt-index">1.</span> Cas pratiques :', $html);
        $this->assertStringContainsString('.qt-alpha { list-style-type: lower-alpha; }', $html);

        // Et le document se fabrique toujours : DomPDF reçoit bien ces balises.
        $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_une_question_du_recapitulatif_pdf_reste_sans_liste_si_elle_n_en_a_pas(): void
    {
        $this->question('Expliquez la saponification en deux ou trois lignes.');

        $attempt = $this->quiz->attempts()->create(['reference' => 'REF2222222']);
        $followLink = ShortLink::forAttempt($this->quiz, $attempt);

        $html = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'revealsCorrection' => true,
            'revealMoment' => $this->quiz->quizRevealMoment(),
            'pending' => 0,
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
        ])->render();

        $this->assertStringNotContainsString('<ol', $html);
        $this->assertStringContainsString('Expliquez la saponification en deux ou trois lignes.', $html);
    }

    public function test_les_vues_ne_partagent_pas_de_mise_en_forme_divergente(): void
    {
        // Le formateur est unique : les cinq écrans doivent tous passer par lui.
        foreach ([
            'admin/quizzes/show.blade.php',
            'partials/grading-answer.blade.php',
            'student/quiz/_question_card.blade.php',
            'student/quiz/result.blade.php',
            'student/quiz/recap-pdf.blade.php',
        ] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('partials.question-text', $source, $view.' doit passer par le fragment d\'énoncé.');
            $this->assertStringNotContainsString('{{ $question->field_label }}', $source, $view.' affiche encore l\'énoncé brut.');
        }
    }
}

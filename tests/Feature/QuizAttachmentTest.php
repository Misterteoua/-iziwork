<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttachment;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pièces jointes en réponse à une question rédigée.
 *
 * Trois idées tiennent ce fichier :
 *   1. une réponse est valide avec un texte, un document, ou les deux ;
 *   2. le serveur refuse ce qui dépasse — trois fichiers, plus de 1 Mo, un format
 *      interdit —, quoi que prétende le formulaire ;
 *   3. les fichiers vivent en privé et ne se téléchargent que par une route qui
 *      revérifie l'accès.
 */
class QuizAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private Form $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00'));

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
            'title' => 'Évaluation avec pièces jointes',
            'token' => 'JETON-PJ-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'is_anonymous' => false,
            'quiz_settings' => [
                'duration_minutes' => 30,
                'show_score' => true,
                'proctoring' => false,
                'requires_reference' => false,
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

    private function openQuestion(): FormField
    {
        return $this->quiz->fields()->create([
            'field_label' => 'Déposez votre travail.',
            'field_type' => FormField::OPEN_TYPE,
            'required' => true,
            'order' => 1,
            'options' => [],
            'correct_answer' => [],
            'expected_answer' => null,
            'points' => 5,
        ]);
    }

    private function start(): QuizAttempt
    {
        $this->openQuestion();

        $this->post(route('quiz.begin', $this->quiz->token), ['student_name' => 'Candidat Test']);

        return $this->quiz->attempts()->orderByDesc('id')->firstOrFail();
    }

    /** @param array<int, UploadedFile> $files */
    private function answerWith(?string $text, array $files = []): TestResponse
    {
        $question = $this->quiz->quizQuestions()->firstOrFail();

        return $this->post(route('quiz.answer', $this->quiz->token), array_filter([
            'question_id' => $question->id,
            'answer_text' => $text,
            'attachments' => $files,
        ], static fn ($value) => $value !== null));
    }

    private function pdf(string $name = 'copie.pdf', int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    private function image(string $name = 'photo.png', int $kilobytes = 300): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 400)->size($kilobytes);
    }

    // ------------------------------------------------------------- Formulaire

    public function test_la_question_redigee_propose_un_champ_de_pieces_jointes(): void
    {
        $this->start();

        $this->get(route('quiz.question', $this->quiz->token))
            ->assertOk()
            ->assertSee('attachments[]', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('1 Mo maximum');
    }

    public function test_les_reglages_proposent_la_correction_manuelle_des_qcm(): void
    {
        $this->get(route('admin.quizzes.show', $this->quiz))
            ->assertOk()
            ->assertSee('manual_choice_grading', false)
            ->assertSee('Corriger les QCM manuellement');
    }

    // ------------------------------------------------------- Dépôt et validation

    public function test_un_texte_seul_reste_une_reponse_valide(): void
    {
        $attempt = $this->start();

        $this->answerWith('Ma réponse rédigée.')->assertRedirect();

        $this->assertDatabaseHas('quiz_answers', [
            'quiz_attempt_id' => $attempt->id,
            'answer_text' => 'Ma réponse rédigée.',
        ]);
        $this->assertSame(0, QuizAttachment::count());
    }

    public function test_un_document_seul_suffit_a_repondre(): void
    {
        $attempt = $this->start();

        $this->answerWith(null, [$this->pdf()])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('quiz_answers', [
            'quiz_attempt_id' => $attempt->id,
            'answer_text' => null,
        ]);
        $this->assertSame(1, QuizAttachment::count());
        $this->assertSame(1, $attempt->fresh()->answers()->count());
    }

    public function test_deux_documents_sont_acceptes(): void
    {
        $this->start();

        $this->answerWith('Voir les deux pièces.', [$this->pdf(), $this->image()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, QuizAttachment::count());
        $this->assertSame(['application/pdf', 'image/png'], QuizAttachment::orderBy('id')->pluck('mime_type')->all());
    }

    public function test_un_troisieme_document_est_refuse(): void
    {
        $this->start();

        $this->answerWith('Trois pièces.', [$this->pdf(), $this->pdf('b.pdf'), $this->pdf('c.pdf')])
            ->assertSessionHasErrors('attachments');

        $this->assertSame(0, QuizAttachment::count());
    }

    public function test_un_document_trop_lourd_est_refuse(): void
    {
        $this->start();

        // 2 Mo : au-delà du 1 Mo autorisé.
        $this->answerWith('Trop gros.', [$this->pdf('gros.pdf', 2048)])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, QuizAttachment::count());
    }

    public function test_un_format_interdit_est_refuse(): void
    {
        $this->start();

        $exe = UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream');

        $this->answerWith('Fichier interdit.', [$exe])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, QuizAttachment::count());
    }

    public function test_ni_texte_ni_document_est_refuse(): void
    {
        $this->start();

        $this->answerWith('   ', [])->assertSessionHasErrors('answer_text');

        $this->assertSame(0, QuizAttachment::count());
    }

    // ------------------------------------------------------------------ Stockage

    public function test_les_fichiers_sont_stockes_en_prive(): void
    {
        $this->start();

        $this->answerWith(null, [$this->pdf('travail.pdf')]);

        $attachment = QuizAttachment::firstOrFail();

        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertStringStartsWith('quiz-attachments/', $attachment->file_path);
        $this->assertSame('travail.pdf', $attachment->original_name);
        $this->assertStringEndsWith('.pdf', $attachment->stored_name);
        // Le nom stocké n'est pas celui de l'utilisateur : un nom forgé ne touche
        // pas le disque.
        $this->assertNotSame($attachment->original_name, $attachment->stored_name);
    }

    // ------------------------------------------------------------ Téléchargement

    public function test_l_etudiant_telecharge_sa_propre_piece_jointe(): void
    {
        $this->start();
        $this->answerWith(null, [$this->pdf()]);

        $attachment = QuizAttachment::firstOrFail();

        $this->get(route('quiz.attachment', [$this->quiz->token, $attachment]))
            ->assertOk()
            ->assertDownload('copie.pdf');
    }

    public function test_l_enseignant_telecharge_la_piece_jointe_de_la_copie(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf()]);

        $attachment = QuizAttachment::firstOrFail();

        $this->get(route('admin.quizzes.attempts.attachment', [$this->quiz, $attempt, $attachment]))
            ->assertOk()
            ->assertDownload('copie.pdf');
    }

    public function test_la_correction_montre_la_piece_jointe(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('mon-travail.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('Pièces jointes')
            ->assertSee('mon-travail.pdf');
    }

    public function test_une_piece_jointe_d_une_autre_copie_est_inaccessible(): void
    {
        $this->start();
        $this->answerWith(null, [$this->pdf()]);
        $attachment = QuizAttachment::firstOrFail();

        // Une autre évaluation, une autre copie : l'identifiant seul ne suffit pas.
        $other = Form::create([
            'title' => 'Autre',
            'token' => 'JETON-AUTRE-'.uniqid(),
            'status' => 'active',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => ['duration_minutes' => 30],
            'created_by' => $this->admin->id,
        ]);

        $otherAttempt = $other->attempts()->create(['reference' => 'ABCDEFGHIJ']);

        $this->get(route('admin.quizzes.attempts.attachment', [$other, $otherAttempt, $attachment]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- Nettoyage

    public function test_la_reinitialisation_efface_les_pieces_jointes(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf()]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = QuizAttachment::firstOrFail();
        $path = $attachment->file_path;

        $this->post(route('admin.quizzes.attempts.reset', [$this->quiz, $attempt]))
            ->assertRedirect();

        $this->assertSame(0, QuizAttachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_la_suppression_de_la_copie_efface_les_pieces_jointes(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf()]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = QuizAttachment::firstOrFail();
        $path = $attachment->file_path;

        $this->delete(route('admin.quizzes.attempts.destroy', [$this->quiz, $attempt]))
            ->assertRedirect();

        $this->assertSame(0, QuizAttachment::count());
        Storage::disk('local')->assertMissing($path);
    }

    // --------------------------------------------------------------- Affichage

    public function test_l_etudiant_retrouve_sa_piece_jointe_dans_son_resultat(): void
    {
        $this->start();
        $this->answerWith(null, [$this->pdf('rendu.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        // Le détail n'est publié qu'une fois l'évaluation fermée.
        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('rendu.pdf');
    }

    /**
     * Un document seul vaut réponse : la correction doit proposer de la noter,
     * et non annoncer « rien à corriger, la question vaut zéro ».
     */
    public function test_la_correction_propose_de_noter_une_reponse_par_document_seul(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('copie-rendue.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('points[', false)
            ->assertSee('En attente')
            ->assertSee('copie-rendue.pdf')
            ->assertDontSee('vaut zéro');
    }

    /**
     * Le parcours complet : une réponse par document seul se corrige, puis la
     * copie quitte la file d'attente.
     */
    public function test_une_reponse_par_document_seul_se_corrige_et_quitte_l_attente(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf()]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $answer = $attempt->answers()->firstOrFail();

        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => 5],
        ])->assertRedirect();

        $this->assertSame('5.00', $answer->refresh()->points_awarded);
        $this->assertTrue($attempt->fresh()->isFullyGraded());
        $this->assertSame(0, $attempt->fresh()->pendingManualCount());
    }

    /**
     * L'étudiant doit lire que sa réponse a bien été rendue par un document,
     * et non « Aucune réponse rendue. ».
     */
    public function test_le_resultat_annonce_une_reponse_par_document_joint(): void
    {
        $this->start();
        $this->answerWith(null, [$this->pdf('rendu.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $this->get(route('quiz.result', $this->quiz->token))
            ->assertOk()
            ->assertSee('Réponse rendue par un document joint.')
            ->assertSee('rendu.pdf')
            ->assertDontSee('Aucune réponse rendue.');
    }

    /**
     * Le récapitulatif PDF porte la même mention : le document de l'étudiant ne
     * doit pas prétendre qu'aucune réponse n'a été rendue.
     */
    public function test_le_recapitulatif_pdf_annonce_une_reponse_par_document_joint(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('rendu.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);
        $attempt->load(['answers', 'attachments']);

        $followLink = ShortLink::forAttempt($this->quiz, $attempt);

        $html = view('student.quiz.recap-pdf', [
            'quiz' => $this->quiz,
            'attempt' => $attempt,
            'showScore' => true,
            'revealsCorrection' => true,
            'revealMoment' => $this->quiz->quizRevealMoment(),
            'pending' => $attempt->pendingManualCount(),
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
        ])->render();

        $this->assertStringContainsString('Réponse rendue par un document joint.', $html);
        $this->assertStringContainsString('rendu.pdf', $html);
        $this->assertStringNotContainsString('Aucune réponse rendue.', $html);
    }

    /**
     * La liste des copies à corriger signale une réponse rendue par un document
     * seul, pour que le correcteur ne la confonde pas avec une absence.
     */
    public function test_la_liste_des_copies_signale_une_reponse_par_document(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('rendu.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->assertSame(1, $attempt->answers()->pendingDocumentOnly()->count());

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertSee('rendue(s) par document joint');

        // La note posée, la réponse n'attend plus : le signal s'éteint.
        $answer = $attempt->answers()->firstOrFail();
        $this->post(route('admin.quizzes.attempts.grade.store', [$this->quiz, $attempt]), [
            'points' => [$answer->id => 5],
        ])->assertRedirect();

        $this->assertSame(0, $attempt->answers()->pendingDocumentOnly()->count());
        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertDontSee('rendue(s) par document joint');
    }

    /**
     * Une réponse accompagnée d'un texte n'est pas « rendue par un document » :
     * l'indicateur doit rester éteint.
     */
    public function test_une_reponse_avec_texte_ne_declenche_pas_l_indicateur(): void
    {
        $attempt = $this->start();
        $this->answerWith('Ma réponse rédigée.', [$this->pdf()]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->assertSame(0, $attempt->answers()->pendingDocumentOnly()->count());

        $this->get(route('admin.quizzes.results', $this->quiz))
            ->assertOk()
            ->assertDontSee('rendue(s) par document joint');
    }

    /**
     * L'aperçu d'une image évite au correcteur de télécharger chaque fichier :
     * la correction affiche la vignette, servie en ligne par la même route.
     */
    public function test_la_correction_affiche_un_apercu_des_images(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->image('scan.png')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('<img', false)
            ->assertSee('apercu=1', false)
            // La vignette ouvre la visionneuse sur la page (`:target`), pas un
            // nouvel onglet : le lien vise l'ancre de la boîte, et la boîte
            // existe dans le document.
            ->assertSee('href="#apercu-', false)
            ->assertSee('class="izw-lightbox"', false)
            ->assertSee('role="dialog"', false);
    }

    /** Un document n'est jamais montré dans la page : il reste un lien. */
    public function test_un_pdf_ne_declenche_pas_d_apercu(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('copie.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $this->get(route('admin.quizzes.attempts.grade', [$this->quiz, $attempt]))
            ->assertOk()
            ->assertSee('copie.pdf')
            ->assertDontSee('apercu=1', false);
    }

    /** L'aperçu d'une image est servi en ligne, avec son type réel. */
    public function test_l_apercu_sert_l_image_en_ligne(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->image('scan.png')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = QuizAttachment::firstOrFail();

        $response = $this->get(route('admin.quizzes.attempts.attachment', [$this->quiz, $attempt, $attachment, 'apercu' => 1]));

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        // Affiché dans la page, jamais proposé au téléchargement.
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /** L'aperçu d'un PDF retombe sur le téléchargement, jamais sur l'affichage. */
    public function test_l_apercu_d_un_pdf_retombe_sur_le_telechargement(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('copie.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = QuizAttachment::firstOrFail();

        $this->get(route('admin.quizzes.attempts.attachment', [$this->quiz, $attempt, $attachment, 'apercu' => 1]))
            ->assertOk()
            ->assertDownload('copie.pdf');
    }

    /**
     * Le récapitulatif PDF cite les pièces jointes par un lien cliquable, pour
     * qu'on puisse les ouvrir depuis le document lui-même — pas seulement lire
     * leur nom.
     */
    public function test_le_recapitulatif_pdf_lie_les_pieces_jointes(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('rendu.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = $attempt->attachments()->firstOrFail();

        // Le détail n'est publié qu'une fois l'évaluation fermée.
        $this->quiz->update(['close_date' => Carbon::now()->subMinute()]);

        $response = $this->get(route('quiz.recap.pdf', [$this->quiz->token, $attempt->reference]));
        $response->assertOk();

        $pdf = (string) $response->getContent();
        $target = '/recap/'.$attempt->reference.'/pieces-jointes/'.$attachment->id;

        // Le lien est écrit dans le PDF (annotation d'URI), pas seulement le nom.
        $this->assertStringContainsString($target, $pdf);
        $this->assertStringContainsString('/URI', $pdf);
    }

    /** Le lien du récapitulatif ouvre bien la pièce, sans session de copie. */
    public function test_le_lien_du_recapitulatif_ouvre_la_piece_jointe(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf('rendu.pdf')]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = $attempt->attachments()->firstOrFail();

        $this->get(route('quiz.recap.attachment', [$this->quiz->token, $attempt->reference, $attachment]))
            ->assertOk()
            ->assertDownload('rendu.pdf');
    }

    /** Une autre référence n'ouvre pas la pièce : la clé ne vaut que pour sa copie. */
    public function test_le_lien_du_recapitulatif_reste_ferme_avec_une_autre_reference(): void
    {
        $attempt = $this->start();
        $this->answerWith(null, [$this->pdf()]);
        $this->post(route('quiz.submit', $this->quiz->token));

        $attachment = $attempt->attachments()->firstOrFail();

        $this->get(route('quiz.recap.attachment', [$this->quiz->token, 'ZZZZZZZZZZ', $attachment]))
            ->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttachment;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    /** @param array<int, \Illuminate\Http\UploadedFile> $files */
    private function answerWith(?string $text, array $files = []): \Illuminate\Testing\TestResponse
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
}

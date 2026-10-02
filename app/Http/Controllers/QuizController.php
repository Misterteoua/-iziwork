<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuestionImport;
use App\Models\QuizAttempt;
use App\Models\QuizGradeReview;
use App\Models\ShortLink;
use App\Support\Import\ImportException;
use App\Support\Import\ImportOutcome;
use App\Support\Import\QuestionSheet;
use App\Support\Import\QuestionSplitter;
use App\Support\Import\QuizTemplate;
use App\Support\Import\StudentRoster;
use App\Support\Import\TabularFile;
use App\Support\QuestionText;
use App\Support\QuizFilters;
use App\Support\QuizQuestionData;
use App\Support\QuizReference;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Évaluations en ligne, côté administrateur.
 *
 * Volontairement séparé de FormController : le module de dépôt de travaux ne
 * doit pas changer de comportement parce qu'un questionnaire existe. Les deux
 * partagent la table `forms`, distinguée par la colonne `type`.
 */
class QuizController extends Controller
{
    /** Séparateur et encodage du CSV, comme pour l'export des soumissions. */
    private const CSV_SEPARATOR = ';';

    /**
     * Écriture d'un import refusée par la base.
     *
     * La transaction est annulée : rien n'a été enregistré, et le dire vaut
     * mieux qu'une page blanche sans trace. C'est très exactement ce que
     * produisait un énoncé plus long que sa colonne en production (MySQL refuse
     * ce que SQLite accepte).
     */
    private const IMPORT_WRITE_FAILED = "L'import n'a pas pu être enregistré : rien n'a été ajouté à l'évaluation. "
        .'Réessayez dans un instant ; si le problème persiste, signalez-le à l\'administrateur (le détail est journalisé).';

    private const CSV_COLUMNS = [
        'Référence',
        'Nom',
        'Email',
        'Filière',
        'Statut',
        'Note',
        'Barème',
        'Temps (min)',
        'Réponses libres à corriger',
        'Infractions',
        'Commencée le',
        'Terminée le',
        // Ajoutée en fin de ligne : les colonnes existantes ne bougent pas, un
        // tableur qui suit l'ordre ancien continue de lire ce qu'il lisait.
        'Adresse IP',
    ];

    /**
     * Réponses rédigées encore sans note.
     *
     * Le filtre lui-même vit sur le modèle ({@see QuizAnswer::scopePendingManual}) :
     * la page des résultats et l'enchaînement des corrections doivent compter
     * exactement la même chose.
     */
    private static function pendingManualFilter($query): void
    {
        $query->pendingManual();
    }

    public function index(Request $request)
    {
        $filters = QuizFilters::fromRequest($request);

        $base = Form::where('type', Form::TYPE_QUIZ)
            ->withCount([
                'fields as questions_count' => fn ($query) => $query->whereIn('field_type', FormField::ANSWER_TYPES),
                'attempts',
                'attempts as submitted_count' => fn ($query) => $query->whereIn(
                    'status',
                    [QuizAttempt::STATUS_SUBMITTED, QuizAttempt::STATUS_EXPIRED]
                ),
            ]);

        // Le total est compté avant filtrage : c'est lui qui permet d'afficher
        // « 1 affichée sur 3 », et donc de comprendre qu'une évaluation manque.
        $total = (clone $base)->count();
        $quizzes = $filters->apply($base)->get();

        return view('admin.quizzes.index', [
            'quizzes' => $quizzes,
            'filters' => $filters,
            'isFiltered' => $filters->isActive(),
            'total' => $total,
        ]);
    }

    public function create()
    {
        return view('admin.quizzes.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateSettings($request);

        $quiz = Form::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'token' => Str::random(32),
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => $this->settingsPayload($request, $validated),
            'created_by' => $request->session()->get('admin_user.id'),
        ]);

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', 'Évaluation créée. Ajoutez maintenant vos questions.');
    }

    public function show(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $quiz->load('fields');
        $questions = $quiz->quizQuestions()->get();
        $attempts = $quiz->attempts()->orderByDesc('created_at')->get();

        // Le lien court de l'évaluation : créé à la première ouverture de cette
        // page, puis stable — un enseignant qui a noté le lien quelque part doit
        // le retrouver identique.
        $shortLink = ShortLink::forQuiz($quiz);

        // Un import en attente de relecture est annoncé ici : c'est le seul
        // endroit d'où un enseignant qui revient — après une déconnexion, depuis
        // un autre poste — peut le retrouver.
        $pendingImport = QuestionImport::pendingFor($quiz, $request->session()->get('admin_user.id'));

        return view('admin.quizzes.show', compact('quiz', 'questions', 'attempts', 'shortLink', 'pendingImport'));
    }

    public function updateSettings(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $validated = $this->validateSettings($request);

        $quiz->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'quiz_settings' => $this->settingsPayload($request, $validated, $quiz),
        ]);

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', 'Réglages enregistrés.');
    }

    public function toggle(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $quiz->update(['status' => $quiz->status === 'active' ? 'inactive' : 'active']);

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', $quiz->status === 'active'
                ? 'Évaluation ouverte aux étudiants.'
                : 'Évaluation fermée.');
    }

    public function destroy(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $quiz->delete();

        return redirect()->route('admin.quizzes.index')
            ->with('success', 'Évaluation supprimée.');
    }

    // ------------------------------------------------------------- Questions

    public function storeQuestion(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $question = $this->validatedQuestion($request);

        $quiz->fields()->create(QuizQuestionData::attributes(
            $question,
            (int) $quiz->quizQuestions()->max('order') + 1
        ));

        return back()->with('success', 'Question ajoutée.');
    }

    public function updateQuestion(Request $request, Form $quiz, FormField $field)
    {
        $this->assertQuiz($quiz);
        $this->assertQuestion($quiz, $field);

        $question = $this->validatedQuestion($request);

        $field->update([
            'field_label' => $question['field_label'],
            'field_type' => $question['field_type'],
            'options' => $question['options'] ?? [],
            'correct_answer' => $question['correct_answer'] ?? [],
            // Une question qui repasse de rédigée à QCM perd son guide : le
            // laisser en base donnerait une colonne qui ne veut plus rien dire.
            'expected_answer' => $question['expected_answer'] ?? null,
            'points' => $question['points'],
        ]);

        return back()->with('success', 'Question mise à jour.');
    }

    public function destroyQuestion(Form $quiz, FormField $field)
    {
        $this->assertQuiz($quiz);
        $this->assertQuestion($quiz, $field);

        $field->delete();

        return back()->with('success', 'Question supprimée.');
    }

    /**
     * Rend la mise en forme de l'énoncé en cours de saisie.
     *
     * L'aperçu ne réécrit rien et ne crée rien : il montre. C'est pourquoi il
     * répond même sur un énoncé invalide (trop court, en cours d'écriture) — le
     * refus, lui, reste au moment de l'enregistrement.
     */
    public function previewQuestion(Request $request, Form $quiz): JsonResponse
    {
        $this->assertQuiz($quiz);

        // Une requête forgée peut envoyer un texte sans limite : on le plafonne
        // à ce qu'un énoncé peut faire, plutôt que de mettre le serveur au
        // travail sur ce qui ne pourrait jamais être enregistré.
        $label = mb_substr((string) $request->input('field_label', ''), 0, QuizQuestionData::MAX_LABEL_LENGTH);

        return response()->json(['html' => (string) QuestionText::html($label)]);
    }

    // ------------------------------------------------------------ Références

    /**
     * Prépare des références d'anonymat.
     *
     * Dès qu'une seule référence existe, l'évaluation passe en mode « liste » :
     * l'étudiant devra saisir la sienne, et une référence inconnue est refusée.
     * C'est ce qui permet de distribuer les références avant l'épreuve.
     */
    public function generateReferences(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:500'],
        ], [
            'count.required' => 'Indiquez le nombre de références à générer.',
            'count.integer' => 'Le nombre de références doit être un nombre entier.',
            'count.min' => 'Générez au moins une référence.',
            'count.max' => 'Cinq cents références au maximum en une fois.',
        ]);

        for ($i = 0; $i < (int) $validated['count']; $i++) {
            $quiz->attempts()->create(['reference' => QuizReference::generate()]);
        }

        // Des références existent : l'entrée se fera par référence, et cela ne doit
        // plus dépendre du nombre de participations en cours.
        $quiz->markReferencesPrepared();

        return back()->with('success', $validated['count'].' référence(s) générée(s).');
    }

    /**
     * Import des questions depuis un fichier Excel, Word, CSV ou texte.
     *
     * Les lignes valides sont créées, les autres sont listées avec leur numéro
     * et leur motif : importer quarante-sept questions sur cinquante en silence
     * se découvrirait le jour de l'épreuve.
     *
     * L'écran d'administration passe par l'aperçu ({@see previewQuestionsImport()}),
     * mais cette route reste l'écriture directe : c'est elle que la confirmation
     * utilise, et un POST direct au fichier s'importe exactement comme avant.
     */
    public function importQuestions(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        try {
            [$questions, $outcome] = QuestionSheet::parse($this->uploadedRows($request));
        } catch (ImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->writeQuestions($quiz, $questions, $outcome);
    }

    /**
     * Étape 1 de l'import : l'analyse, qui n'écrit aucune question.
     *
     * Le fichier est analysé et ses questions sont montrées telles qu'elles
     * seront créées — découpages compris. Rien n'est enregistré tant que
     * l'enseignant n'a pas confirmé : un fichier de cinquante questions qui
     * arrive de travers se voit avant de peser sur l'épreuve.
     *
     * Le jeu analysé est gardé en base ({@see QuestionImport}), pas en session :
     * l'enseignant peut fermer son navigateur, se déconnecter, ou reprendre
     * depuis un autre poste — son aperçu l'attend.
     */
    public function previewQuestionsImport(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        try {
            [$questions, $outcome] = QuestionSheet::parse($this->uploadedRows($request));
        } catch (ImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        $adminId = $request->session()->get('admin_user.id');

        QuestionImport::sweep();

        // Un seul aperçu en attente par enseignant et par évaluation : le
        // nouveau remplace l'ancien, sinon la page de l'évaluation proposerait
        // deux reprises pour le même travail.
        QuestionImport::query()
            ->where('form_id', $quiz->getKey())
            ->where('admin_user_id', $adminId)
            ->delete();

        $import = QuestionImport::create([
            'form_id' => $quiz->getKey(),
            'admin_user_id' => $adminId,
            'questions' => $questions,
            'errors' => $outcome->errors,
            'ignored' => $outcome->ignored,
        ]);

        return $this->questionImportPreview($import);
    }

    /**
     * La page de relecture, atteignable directement : c'est elle qui rend
     * l'aperçu survivable. Après une déconnexion ou depuis un autre appareil,
     * l'enseignant rouvre ce lien et retrouve son import en attente, sans
     * retéléverser le fichier.
     */
    public function reviewQuestionsImport(Request $request, Form $quiz, QuestionImport $import)
    {
        if ($redirect = $this->unusableImport($request, $quiz, $import)) {
            return $redirect;
        }

        // Un refus de relecture traverse la redirection : il revient ici, à la
        // place de la question qu'il concerne.
        return $this->questionImportPreview(
            $import,
            (array) $request->session()->pull('import_question_errors', [])
        );
    }

    /**
     * La relecture : ranger, puis confirmer.
     *
     * Quatre actions, un seul formulaire : les énoncés modifiés dans l'aperçu
     * voyagent avec le clic, donc aucun n'est perdu en chemin — et ils sont
     * enregistrés dans l'aperçu, qui reste juste même après un aller-retour.
     *   - `confirm` : chaque question repasse par les règles de la saisie
     *     manuelle, et c'est la seule action qui écrit ;
     *   - `merge:N` : recoud la question N à la précédente, quand les deux
     *     viennent du même énoncé découpé ;
     *   - `split:N` : coupe la question N aux lignes de coupure qu'elle contient ;
     *   - `remove:N` : écarte la question N.
     */
    public function applyQuestionsImport(Request $request, Form $quiz, QuestionImport $import)
    {
        if ($redirect = $this->unusableImport($request, $quiz, $import)) {
            return $redirect;
        }

        $questions = $this->editedQuestions($import->questionSet(), $request->input('questions', []));
        $errors = (array) ($import->errors ?? []);
        $ignored = (int) $import->ignored;
        $action = (string) $request->input('action', 'confirm');

        // Une relecture qui range, sans écrire de question : le jeu mis à jour
        // est enregistré, et la page de relecture se recharge — un rafraîchissement
        // ne rejoue donc pas l'action une seconde fois.
        if (preg_match('/^(merge|remove|split):(\d+)$/', $action, $found) === 1) {
            $index = (int) $found[2];
            $before = count($questions);

            $questions = match ($found[1]) {
                'remove' => $this->removeQuestion($questions, $index),
                'split' => $this->splitQuestion($questions, $index),
                default => $this->mergeSplit($questions, $index),
            };

            $import->update(['questions' => $this->renumberSplits($questions)]);

            // Rien n'a bougé : le clic visait une question sans coupure. Le dire
            // vaut mieux qu'un écran qui semble n'avoir rien entendu.
            if ($found[1] === 'split' && $import->questionCount() === $before) {
                $request->session()->flash('import_question_errors', [
                    $index => 'Aucune coupure trouvée : insérez une ligne « --- » à l\'endroit de la scission.',
                ]);
            }

            return redirect()->route('admin.quizzes.questions.import.review', [$quiz, $import]);
        }

        // Confirmation : une retouche invalide revient à l'écran, elle n'est pas
        // écrite — le même contrôle que la saisie manuelle décide.
        $import->update(['questions' => $questions]);

        $normalized = [];
        $questionErrors = [];

        foreach ($questions as $index => $question) {
            try {
                $normalized[] = $this->normalizeSubmittedQuestion($question);
            } catch (ValidationException $e) {
                $questionErrors[$index] = (string) (collect($e->errors())->flatten()->first() ?? 'question invalide.');
            }
        }

        if ($questionErrors !== []) {
            $request->session()->flash('import_question_errors', $questionErrors);

            return redirect()->route('admin.quizzes.questions.import.review', [$quiz, $import]);
        }

        if ($normalized === []) {
            return redirect()->route('admin.quizzes.show', $quiz)
                ->with('error', "Aucune question à importer : relancez l'import du fichier.");
        }

        // L'aperçu a fait son travail : il disparaît, donc il ne peut pas être
        // confirmé deux fois, ni rester proposé sur la page de l'évaluation.
        $import->delete();

        return $this->writeQuestions($quiz, $normalized, $this->outcomeFor($normalized, $errors, $ignored));
    }

    /**
     * Abandonner un aperçu en attente, sans rien importer.
     */
    public function discardQuestionsImport(Request $request, Form $quiz, QuestionImport $import): RedirectResponse
    {
        if ($redirect = $this->unusableImport($request, $quiz, $import)) {
            return $redirect;
        }

        $import->delete();

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', "L'aperçu d'import a été abandonné : aucune question n'a été ajoutée.");
    }

    /**
     * L'aperçu peut-il être ouvert par cet enseignant, sur cette évaluation ?
     *
     * Deux gardes : l'aperçu appartient à une évaluation et à un auteur, et il
     * ne survit pas à une semaine — au-delà, le fichier importé a probablement
     * changé, et le reprendre écrirait des questions que personne n'a relues.
     */
    private function unusableImport(Request $request, Form $quiz, QuestionImport $import): ?RedirectResponse
    {
        $this->assertQuiz($quiz);

        abort_unless(
            $import->form_id === $quiz->getKey()
            && $import->admin_user_id === $request->session()->get('admin_user.id'),
            404
        );

        if (! $import->isExpired()) {
            return null;
        }

        $import->delete();

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('error', "Cet aperçu a expiré : relancez l'import du fichier.");
    }

    /**
     * Import de la liste des étudiants : une référence est générée pour chacun.
     *
     * Chaque ligne devient une participation en attente, qui porte le nom et
     * l'email importés. C'est la référence — et non le nom — que l'étudiant devra
     * saisir : pour une évaluation anonyme, c'est elle qui tient lieu de numéro
     * d'anonymat, et les noms restent hors des résultats et des exports.
     */
    public function importStudents(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        try {
            [$students, $outcome] = StudentRoster::parse($this->uploadedRows($request));
        } catch (ImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        $existingEmails = $quiz->attempts()
            ->whereNotNull('student_email')
            ->pluck('student_email')
            ->map(static fn ($email): string => mb_strtolower((string) $email))
            ->all();

        $skipped = [];

        try {
            DB::transaction(function () use ($quiz, $students, $existingEmails, &$skipped): void {
                foreach ($students as $student) {
                    if ($student['email'] !== null && in_array($student['email'], $existingEmails, true)) {
                        $skipped[] = $student['name'].' ('.$student['email'].') figure déjà dans la liste.';

                        continue;
                    }

                    $quiz->attempts()->create([
                        'reference' => QuizReference::generate(),
                        'student_name' => $student['name'],
                        'student_email' => $student['email'],
                        'student_major' => $student['major'],
                    ]);
                }
            });
        } catch (QueryException $e) {
            return $this->importWriteFailed($e);
        }

        $outcome->ignored += count($skipped);

        // Une liste nominative importée fige le mode d'accès, même si toutes les
        // copies sont rendues ou réinitialisées par la suite.
        $quiz->markReferencesPrepared();

        return back()->with('import_students', [
            'summary' => $outcome->summary('étudiant importé', 'étudiants importés'),
            'errors' => array_merge($outcome->shownErrors(), $skipped),
            'hidden' => $outcome->hiddenErrorsCount(),
        ]);
    }

    /** Modèle Excel des questions, à remplir puis à importer. */
    public function questionTemplate()
    {
        return $this->template(QuizTemplate::questionRows(), 'Questions', 'modele-questions-evaluation.xlsx');
    }

    /** Modèle Excel de la liste des étudiants. */
    public function studentTemplate()
    {
        return $this->template(QuizTemplate::studentRows(), 'Etudiants', 'modele-liste-etudiants.xlsx');
    }

    /**
     * Liste des références à distribuer.
     *
     * Fichier nominatif : c'est la liste de distribution de l'enseignant, celui
     * qui a importé les noms. Il n'est jamais montré à un étudiant, et l'export
     * des résultats, lui, masque les noms des évaluations anonymes.
     */
    public function exportReferences(Form $quiz): StreamedResponse
    {
        $this->assertQuiz($quiz);

        $attempts = $quiz->attempts()->orderBy('reference');

        return response()->streamDownload(function () use ($attempts): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            $this->writeCsvRow($handle, ['Référence', 'Nom', 'Email', 'Filière', 'État']);

            $attempts->chunk(200, function ($rows) use ($handle): void {
                foreach ($rows as $attempt) {
                    $this->writeCsvRow($handle, [
                        $attempt->reference,
                        $attempt->student_name,
                        $attempt->student_email,
                        $attempt->student_major,
                        $this->statusLabel($attempt->status),
                    ]);
                }
            });

            fclose($handle);
        }, 'references-evaluation-'.$quiz->id.'-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Le lien de suivi d'un étudiant : créé au premier appel, retrouvé identique
     * ensuite. La réponse est copiée par le navigateur, jamais affichée seule.
     */
    public function resultLink(Form $quiz, QuizAttempt $attempt): JsonResponse
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        // Un lien de suivi n'a de sens que pour une copie rendue.
        abort_unless($attempt->isFinished(), 404);

        return response()->json(['url' => ShortLink::forAttempt($quiz, $attempt)->url()]);
    }

    /**
     * Un nouveau code pour cette copie : l'ancien lien cesse aussitôt de
     * fonctionner. C'est la façon d'annuler un lien qu'on juge compromis.
     */
    public function regenerateResultLink(Form $quiz, QuizAttempt $attempt): JsonResponse
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        abort_unless($attempt->isFinished(), 404);

        $link = ShortLink::forAttempt($quiz, $attempt);
        $link->rotate();

        return response()->json(['url' => $link->url()]);
    }

    public function resetAttempt(Form $quiz, QuizAttempt $attempt)
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        // Le journal de relecture d'une réponse disparaît avec elle : une revue
        // sans réponse à examiner ne dit plus rien. La suppression est explicite
        // plutôt que confiée à la cascade, parce que la même règle doit tenir sur
        // SQLite comme sur MySQL.
        QuizGradeReview::whereIn('quiz_answer_id', $attempt->answers()->pluck('id'))->delete();

        $attempt->answers()->delete();

        $attempt->update([
            'status' => QuizAttempt::STATUS_PENDING,
            'started_at' => null,
            'expires_at' => null,
            'submitted_at' => null,
            'score' => null,
            'max_score' => null,
            'infractions' => [],
            'infraction_count' => 0,
        ]);

        return back()->with('success', 'Participation réinitialisée : l\'étudiant peut repasser l\'évaluation.');
    }

    /**
     * Supprime une copie et tout ce qui s'y rattache.
     *
     * À distinguer de la réinitialisation, qui garde la participation et laisse
     * l'étudiant repasser : ici, la ligne disparaît. En mode « liste préparée »,
     * la référence disparaît avec elle — et le candidat ne peut donc plus
     * commencer. C'est dit dans la confirmation, parce que la conséquence est
     * invisible après coup.
     *
     * Partent avec la copie : ses réponses, le journal des notes revues, et le
     * lien de suivi de l'étudiant, qui mènerait sinon à une page vide.
     */
    public function destroyAttempt(Form $quiz, QuizAttempt $attempt)
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        $reference = $attempt->reference;

        DB::transaction(function () use ($attempt): void {
            $answerIds = $attempt->answers()->pluck('id');

            QuizGradeReview::whereIn('quiz_answer_id', $answerIds)->delete();
            $attempt->answers()->delete();

            // Le lien de suivi d'une copie supprimée mènerait à une page vide :
            // il s'en va avec elle.
            ShortLink::where('quiz_attempt_id', $attempt->id)->delete();

            $attempt->delete();
        });

        return back()->with('success', 'Copie '.$reference.' supprimée.');
    }

    // --------------------------------------------------------------- Résultats

    public function results(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempts = $quiz->attempts()
            ->withCount([
                'answers',
                // Compté une fois pour toutes : c'est ce qui permet d'afficher
                // « 3 copies à corriger » sans une requête par ligne.
                'answers as pending_manual_count' => fn ($query) => self::pendingManualFilter($query),
                // Réponses dont la note a été revue : le rappel qu'un second
                // niveau de relecture est passé par là, donc qu'une note peut
                // ne plus être celle du correcteur qui l'avait posée.
                'answers as reviewed_count' => fn ($query) => $query->whereHas('reviews'),
            ])
            ->orderByDesc('submitted_at')
            ->orderBy('reference')
            ->get();

        // Copies partagées depuis une même adresse IP : un indice, jamais une
        // preuve — une salle informatique entière sort derrière une seule
        // adresse. Le calcul se fait sur la collection déjà chargée, donc sans
        // une requête de plus, et il n'interdit rien : il montre.
        $sharedIps = $attempts
            ->filter(fn (QuizAttempt $attempt) => $attempt->ip_address !== null)
            ->groupBy('ip_address')
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->count());

        return view('admin.quizzes.results', compact('quiz', 'attempts', 'sharedIps'));
    }

    /**
     * Export CSV des résultats, dans le même esprit que l'export des
     * soumissions : BOM pour Excel, point-virgule, formules neutralisées.
     */
    public function exportResults(Form $quiz): StreamedResponse
    {
        $this->assertQuiz($quiz);

        $attempts = $quiz->attempts()
            ->withCount(['answers as pending_manual_count' => fn ($query) => self::pendingManualFilter($query)])
            ->orderByDesc('submitted_at')
            ->orderBy('reference');

        $filename = 'evaluation_'.$quiz->id.'_'.now()->format('Y-m-d_Hi').'.csv';

        $anonymous = $quiz->is_anonymous;

        return response()->streamDownload(function () use ($attempts, $anonymous): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            $this->writeCsvRow($handle, self::CSV_COLUMNS);

            $attempts->chunk(200, function ($rows) use ($handle, $anonymous): void {
                foreach ($rows as $attempt) {
                    // Évaluation anonyme : le nom ne doit pas se retrouver dans
                    // un fichier qui circule, c'est tout l'objet de la référence.
                    $this->writeCsvRow($handle, [
                        $attempt->reference,
                        $anonymous ? null : $attempt->student_name,
                        $attempt->student_email,
                        $attempt->student_major,
                        $this->statusLabel($attempt->status),
                        $attempt->score === null ? null : number_format((float) $attempt->score, 2, ',', ''),
                        $attempt->max_score === null ? null : number_format((float) $attempt->max_score, 2, ',', ''),
                        $attempt->started_at === null ? null : round($attempt->elapsedSeconds() / 60, 1),
                        $attempt->pending_manual_count,
                        $attempt->infraction_count,
                        $attempt->started_at?->format('d/m/Y H:i'),
                        $attempt->submitted_at?->format('d/m/Y H:i'),
                        $attempt->ip_address,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Export des réponses rédigées, une ligne par réponse.
     *
     * Un correcteur ne lit pas cinquante copies dans un tableau : il veut les
     * textes groupés, avec la question, le barème et ce qui lui reste à noter.
     * Une seule requête jointe, donc pas de N+1 sur une promotion entière.
     */
    public function exportOpenAnswers(Form $quiz): StreamedResponse
    {
        $this->assertQuiz($quiz);

        $answers = QuizAnswer::query()
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_answers.quiz_attempt_id')
            ->join('form_fields', 'form_fields.id', '=', 'quiz_answers.form_field_id')
            ->where('quiz_attempts.form_id', $quiz->id)
            ->where('form_fields.field_type', FormField::OPEN_TYPE)
            // Tri total : sans la clé primaire en dernier, deux réponses du même
            // candidat se retrouveraient à égalité et la pagination par paquets
            // pourrait en sauter ou en compter deux fois.
            ->orderBy('quiz_attempts.reference')
            ->orderBy('form_fields.order')
            ->orderBy('form_fields.id')
            ->orderBy('quiz_answers.id')
            ->select([
                'quiz_attempts.reference as reference',
                'quiz_attempts.student_name as student_name',
                'form_fields.field_label as question',
                'form_fields.points as max_points',
                'quiz_answers.answer_text as answer_text',
                'quiz_answers.points_awarded as points_awarded',
            ]);

        $anonymous = $quiz->is_anonymous;
        $filename = 'reponses-redigees_'.$quiz->id.'_'.now()->format('Y-m-d_Hi').'.csv';

        return response()->streamDownload(function () use ($answers, $anonymous): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            $this->writeCsvRow($handle, ['Référence', 'Nom', 'Question', 'Réponse', 'Points', 'Barème', 'Correction']);

            $answers->chunk(200, function ($rows) use ($handle, $anonymous): void {
                foreach ($rows as $row) {
                    $this->writeCsvRow($handle, [
                        $row->reference,
                        // Évaluation anonyme : le nom reste hors du fichier, comme
                        // dans l'export des résultats.
                        $anonymous ? null : $row->student_name,
                        $row->question,
                        $row->answer_text,
                        $row->points_awarded,
                        $row->max_points,
                        $row->points_awarded === null ? 'À corriger' : 'Corrigée',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- Interne

    /**
     * @return array<string, mixed>
     */
    private function validateSettings(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'draw_count' => ['nullable', 'integer', 'min:1', 'max:'.QuestionSheet::MAX_QUESTIONS],
            'max_submissions' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'open_date' => ['nullable', 'date'],
            'close_date' => ['nullable', 'date', 'after_or_equal:open_date'],
            // Date de publication choisie : facultative, et indépendante des
            // bornes d'ouverture — publier les corrections le lendemain d'une
            // épreuve reste possible même si elle reste ouverte une semaine.
            'reveal_answers_at' => ['nullable', 'date'],
        ], [
            'title.required' => 'Le titre de l\'évaluation est obligatoire.',
            'duration_minutes.required' => 'La durée de l\'épreuve est obligatoire.',
            'duration_minutes.min' => 'La durée doit être d\'au moins une minute.',
            'duration_minutes.max' => 'La durée ne peut pas dépasser 600 minutes (10 heures).',
            'max_submissions.integer' => 'Le quota de participants doit être un nombre entier.',
            'draw_count.integer' => 'Le nombre de questions posées doit être un nombre entier.',
            'draw_count.min' => 'Une épreuve comporte au moins une question.',
            'draw_count.max' => 'Le tirage est limité à '.QuestionSheet::MAX_QUESTIONS.' questions.',
            'close_date.after_or_equal' => 'La date de fermeture doit suivre la date d\'ouverture.',
        ]);
    }

    /**
     * Valide une question saisie à la main, puis délègue la mise en forme à
     * {@see QuizQuestionData} — le même code que l'import par fichier, pour que
     * les deux chemins ne puissent pas accepter des choses différentes.
     *
     * @return array{field_label: string, field_type: string, options: array<int, string>, correct_answer: array<int, int>, points: float}
     */
    private function validatedQuestion(Request $request): array
    {
        // Question ouverte : ni propositions, ni bonne réponse. Le branchement se
        // fait ici et non dans la vue, qui n'est qu'un confort d'affichage — un
        // formulaire peut toujours être renvoyé à la main.
        if ($request->input('field_type') === FormField::OPEN_TYPE) {
            $request->validate([
                'field_label' => ['required', 'string', 'max:'.QuizQuestionData::MAX_LABEL_LENGTH],
                'expected_answer' => ['nullable', 'string', 'max:'.QuizQuestionData::MAX_EXPECTED_ANSWER],
                'points' => ['required', 'numeric', 'min:0.5', 'max:100'],
            ], [
                'field_label.required' => 'L\'énoncé de la question est obligatoire.',
                'expected_answer.max' => 'La réponse attendue ne peut pas dépasser '.QuizQuestionData::MAX_EXPECTED_ANSWER.' caractères.',
                'points.required' => 'Indiquez le barème de la question.',
                'points.min' => 'Le barème doit être d\'au moins 0,5 point.',
            ]);

            return QuizQuestionData::normalizeOpen(
                (string) $request->input('field_label'),
                (float) $request->input('points'),
                $request->input('expected_answer')
            );
        }

        $request->validate([
            'field_label' => ['required', 'string', 'max:'.QuizQuestionData::MAX_LABEL_LENGTH],
            'field_type' => ['required', Rule::in(FormField::QUESTION_TYPES)],
            'options' => ['required', 'array', 'min:2', 'max:8'],
            'options.*' => ['nullable', 'string', 'max:500'],
            'correct' => ['required', 'array'],
            'correct.*' => ['integer'],
            'points' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ], [
            'field_label.required' => 'L\'énoncé de la question est obligatoire.',
            'field_type.required' => 'Choisissez le type de question.',
            'field_type.in' => 'Le type de question sélectionné est invalide.',
            'options.required' => 'Proposez au moins deux réponses possibles.',
            'options.min' => 'Une question demande au moins deux propositions.',
            'options.max' => 'Huit propositions au maximum par question.',
            'correct.required' => 'Désignez la bonne réponse.',
            'points.required' => 'Indiquez le barème de la question.',
            'points.min' => 'Le barème doit être d\'au moins 0,5 point.',
        ]);

        return QuizQuestionData::normalize(
            (string) $request->input('field_label'),
            (array) $request->input('options', []),
            array_map('intval', (array) $request->input('correct', [])),
            (float) $request->input('points'),
            (string) $request->input('field_type')
        );
    }

    /**
     * Réglages d'une évaluation, à partir de la requête déjà validée.
     *
     * `requires_reference` n'est pas modifiable depuis le formulaire : il est
     * repris tel quel. C'est la trace du mode d'accès (liste préparée ou libre),
     * et elle ne doit pas se perdre parce qu'un enseignant a changé la durée ou
     * les dates. Pour une évaluation créée avant ce réglage, la reprise classe la
     * question une fois pour toutes au lieu de la laisser être redécidée à chaque
     * étudiant.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function settingsPayload(Request $request, array $validated, ?Form $existing = null): array
    {
        $draw = $validated['draw_count'] ?? null;

        return [
            'duration_minutes' => (int) $validated['duration_minutes'],
            'show_score' => $request->boolean('show_score'),
            'proctoring' => $request->boolean('proctoring'),
            'draw_count' => $draw === null || $draw === '' ? null : (int) $draw,
            'shuffle_questions' => $request->boolean('shuffle_questions'),
            'shuffle_options' => $request->boolean('shuffle_options'),
            'requires_reference' => $existing !== null && $existing->quizHasPreparedReferences(),
            // Anti-doublon par appareil : un réglage, jamais un défaut imposé.
            'one_attempt_per_device' => $request->boolean('one_attempt_per_device'),
            // Date de publication des corrections : null tant que l'enseignant
            // n'en fixe pas, ce qui laisse la fermeture décider.
            'reveal_answers_at' => $this->publicationMoment($validated),
        ];
    }

    /**
     * La date de publication des corrections, normalisée pour `quiz_settings`.
     *
     * Le formulaire envoie une date locale (`datetime-local`, sans secondes) ;
     * on la range sous une forme unique, relisible telle quelle par le modèle.
     * Une valeur vide — ou effacée par l'enseignant — vaut « pas de date » :
     * c'est ce retour en arrière qui rend le réglage réversible.
     *
     * @param  array<string, mixed>  $validated
     */
    private function publicationMoment(array $validated): ?string
    {
        $moment = $validated['reveal_answers_at'] ?? null;

        if (! is_string($moment) || trim($moment) === '') {
            return null;
        }

        return Carbon::parse($moment)->format('Y-m-d H:i:s');
    }

    private function writeCsvRow($handle, array $values): void
    {
        $row = array_map(function ($value): string {
            $value = (string) ($value ?? '');

            // Neutralise les formules : un nom saisi par un étudiant ne doit pas
            // être exécuté par le tableur qui ouvre ce fichier.
            return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
        }, $values);

        fputcsv($handle, $row, self::CSV_SEPARATOR, '"', '');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            QuizAttempt::STATUS_SUBMITTED => 'Terminée',
            QuizAttempt::STATUS_EXPIRED => 'Temps écoulé',
            QuizAttempt::STATUS_IN_PROGRESS => 'En cours',
            default => 'Non commencée',
        };
    }

    /**
     * Lit le fichier envoyé et le rend sous forme de lignes.
     *
     * La validation se fait sur l'extension annoncée puis sur le contenu : la
     * règle `mimes:` a déjà coûté un déploiement, un .xlsx étant annoncé par les
     * navigateurs comme un simple zip. On vérifie donc ce qui est contrôlable, et
     * le lecteur refuse un contenu illisible avec un message clair.
     *
     * @return array<int, array<int, string>>
     */
    private function uploadedRows(Request $request): array
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ], [
            'file.required' => 'Choisissez un fichier à importer.',
            'file.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ]);

        $upload = $request->file('file');
        $extension = strtolower((string) $upload->getClientOriginalExtension());

        if (! in_array($extension, TabularFile::ACCEPTED, true)) {
            throw new ImportException('Format non pris en charge : utilisez un fichier .xlsx, .docx, .csv ou .txt.');
        }

        return TabularFile::rows((string) $upload->getRealPath(), (string) $upload->getClientOriginalName());
    }

    /**
     * Crée les questions retenues et laisse le compte rendu à l'écran.
     *
     * Écriture partagée par l'import direct et par la confirmation de l'aperçu :
     * une seule transaction, donc deux chemins qui ne peuvent pas diverger.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function writeQuestions(Form $quiz, array $questions, ImportOutcome $outcome): RedirectResponse
    {
        try {
            DB::transaction(function () use ($quiz, $questions): void {
                $order = (int) $quiz->quizQuestions()->max('order');

                foreach ($questions as $question) {
                    $quiz->fields()->create(QuizQuestionData::attributes($question, ++$order));
                }
            });
        } catch (QueryException $e) {
            return $this->importWriteFailed($e);
        }

        // Direction la page de l'évaluation : c'est là que le compte rendu
        // s'affiche, et l'aperçu d'import n'existe plus.
        return redirect()->route('admin.quizzes.show', $quiz)->with('import_questions', [
            'summary' => $outcome->summary('question importée', 'questions importées'),
            'errors' => $outcome->shownErrors(),
            'hidden' => $outcome->hiddenErrorsCount(),
            'splits' => $outcome->splits,
        ]);
    }

    /**
     * La page d'aperçu, à partir du jeu de questions en cours de relecture.
     *
     * Le compte rendu de l'analyse (lignes refusées, découpages) vient de
     * l'import, pas de la relecture : il ne change pas parce qu'on a retiré ou
     * recousu une question. Les découpages, eux, sont recomptés sur le jeu en
     * cours — sinon un énoncé recousu resterait annoncé « découpé en 3 ».
     *
     * @param  array<int, string>  $questionErrors  messages par question, après une retouche refusée
     */
    private function questionImportPreview(QuestionImport $import, array $questionErrors = [])
    {
        $questions = $import->questionSet();
        $splits = [];

        foreach ($this->splitCounts($questions) as $line => $count) {
            if ($count >= 2) {
                $splits[] = 'Ligne '.$line.' : énoncé découpé en '.$count.' questions.';
            }
        }

        return view('admin.quizzes.import-preview', [
            'quiz' => $import->form,
            'import' => $import,
            'questions' => $questions,
            'outcome' => new ImportOutcome(count($questions), (array) ($import->errors ?? []), (int) $import->ignored, $splits),
            'questionErrors' => $questionErrors,
        ]);
    }

    /**
     * Applique les énoncés modifiés dans l'aperçu au jeu gardé en session.
     *
     * Seul l'énoncé est modifiable : les propositions, la bonne réponse et le
     * type ne le sont pas, et restent donc ceux de l'analyse. Un formulaire
     * vide (réponse d'un ancien onglet) laisse le jeu tel quel.
     *
     * @param  array<int, array<string, mixed>>  $stored
     * @param  mixed  $posted
     * @return array<int, array<string, mixed>>
     */
    private function editedQuestions(array $stored, $posted): array
    {
        if (! is_array($posted) || $posted === []) {
            return $stored;
        }

        $edited = [];

        // Les clés du formulaire sont les rangs affichés : on s'en sert pour
        // retrouver la question, jamais pour deviner un ordre.
        foreach ($posted as $index => $row) {
            $question = $stored[(int) $index] ?? null;

            if ($question === null) {
                continue;
            }

            if (is_array($row)) {
                $question['field_label'] = (string) ($row['label'] ?? '');
            }

            $edited[] = $question;
        }

        return $edited;
    }

    /**
     * Écarte une question de l'import.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function removeQuestion(array $questions, int $index): array
    {
        if (! array_key_exists($index, $questions)) {
            return $questions;
        }

        unset($questions[$index]);

        return array_values($questions);
    }

    /**
     * Recoud la question N à la précédente.
     *
     * Seuls deux morceaux d'un même énoncé se recousent : le contexte recopié
     * est retiré de la seconde avant la jonction, sinon il apparaîtrait deux
     * fois. Une fusion entre deux questions sans rapport est ignorée.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function mergeSplit(array $questions, int $index): array
    {
        if ($index < 1 || ! isset($questions[$index - 1], $questions[$index])) {
            return $questions;
        }

        $previous = $questions[$index - 1];
        $current = $questions[$index];
        $group = $this->originOf($previous);

        if ($group === null || $group !== $this->originOf($current)) {
            return $questions;
        }

        $previous['field_label'] = QuestionSplitter::join((string) $previous['field_label'], (string) $current['field_label']);

        $questions[$index - 1] = $previous;
        unset($questions[$index]);

        return array_values($questions);
    }

    /**
     * Scinde la question N aux lignes de coupure qu'elle contient.
     *
     * Les morceaux gardent l'origine de leur question d'origine : scinder une
     * question déjà découpée laisse donc ses morceaux recousables entre eux, et
     * ne fabrique pas un énoncé sans parent. Une question sans coupure est
     * laissée telle quelle — le bouton n'a rien à couper.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function splitQuestion(array $questions, int $index): array
    {
        if (! isset($questions[$index])) {
            return $questions;
        }

        $question = $questions[$index];
        $parts = QuestionSplitter::cut((string) ($question['field_label'] ?? ''));

        if ($parts === []) {
            return $questions;
        }

        $group = $this->originOf($question) ?? 'manuel:'.Str::random(8);
        $pieces = [];

        foreach ($parts as $part) {
            $piece = $question;
            $piece['field_label'] = $part;
            $piece['group'] = $group;
            $pieces[] = $piece;
        }

        array_splice($questions, $index, 1, $pieces);

        return array_values($questions);
    }

    /**
     * L'origine d'une question : le groupe dont elle est un morceau, ou null.
     *
     * `group` couvre les découpages automatiques comme manuels ; `split_line`
     * reste lu pour les jeux enregistrés avant que le groupe existe.
     *
     * @param  array<string, mixed>  $question
     */
    private function originOf(array $question): ?string
    {
        if (($question['group'] ?? null) !== null) {
            return (string) $question['group'];
        }

        return ($question['split_line'] ?? null) !== null ? 'ligne:'.$question['split_line'] : null;
    }

    /**
     * Renumérote les morceaux d'un même énoncé, et retire le repère quand il
     * n'en reste qu'un : une question seule n'est plus « 1/3 ».
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function renumberSplits(array $questions): array
    {
        $groups = [];

        foreach ($questions as $index => $question) {
            $group = $this->originOf($question);

            if ($group !== null) {
                $groups[$group][] = $index;
            }
        }

        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                foreach ($indexes as $index) {
                    unset($questions[$index]['group'], $questions[$index]['split_line'], $questions[$index]['split_index'], $questions[$index]['split_total']);
                }

                continue;
            }

            foreach ($indexes as $position => $index) {
                $questions[$index]['split_index'] = $position + 1;
                $questions[$index]['split_total'] = count($indexes);
            }
        }

        return $questions;
    }

    /**
     * Repasse une question relue par les règles de la saisie manuelle.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function normalizeSubmittedQuestion(array $question): array
    {
        $label = (string) ($question['field_label'] ?? '');
        $points = (float) ($question['points'] ?? 1);
        $type = (string) ($question['field_type'] ?? '');

        $meta = array_filter([
            'group' => $question['group'] ?? null,
            'split_line' => $question['split_line'] ?? null,
            'split_index' => $question['split_index'] ?? null,
            'split_total' => $question['split_total'] ?? null,
        ], static fn ($value): bool => $value !== null);

        if ($type === FormField::OPEN_TYPE) {
            return QuizQuestionData::normalizeOpen($label, $points) + $meta;
        }

        $options = array_map('strval', (array) ($question['options'] ?? []));
        $correct = array_map('intval', (array) ($question['correct_answer'] ?? []));
        $declared = in_array($type, FormField::QUESTION_TYPES, true) ? $type : null;

        return QuizQuestionData::normalize($label, $options, $correct, $points, $declared) + $meta;
    }

    /**
     * Le compte rendu de l'import, à partir de ce qui a été écrit.
     *
     * Les lignes refusées viennent de l'analyse du fichier (elles ne sont pas
     * réécrites ici) ; les découpages sont recomptés sur le jeu final, pour
     * qu'une fusion ne soit pas annoncée comme un découpage.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array<int, string>  $errors
     */
    private function outcomeFor(array $questions, array $errors, int $ignored): ImportOutcome
    {
        $outcome = new ImportOutcome(count($questions), $errors, $ignored);

        foreach ($this->splitCounts($questions) as $line => $count) {
            if ($count >= 2) {
                $outcome->addSplit($line, $count, $count);
            }
        }

        return $outcome;
    }

    /**
     * Combien de questions restent de chaque énoncé découpé, par ligne d'origine.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, int>
     */
    private function splitCounts(array $questions): array
    {
        $counts = [];

        foreach ($questions as $question) {
            $line = $question['split_line'] ?? null;

            if ($line !== null) {
                $counts[$line] = ($counts[$line] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Téléchargement d'un modèle, avec un message clair si le serveur ne peut
     * pas le fabriquer (extension ZIP inactive).
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function template(array $rows, string $sheetName, string $filename)
    {
        try {
            $content = QuizTemplate::xlsx($rows, $sheetName);
        } catch (ImportException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * L'import n'a pas pu être écrit : le dire, et le journaliser.
     *
     * Rien n'est ajouté — la transaction est annulée — donc l'enseignant peut
     * reprendre son fichier tel quel après avoir compris ce qui bloque.
     */
    private function importWriteFailed(QueryException $e): RedirectResponse
    {
        report($e);

        return back()->with('error', self::IMPORT_WRITE_FAILED);
    }

    private function assertQuiz(Form $quiz): void
    {
        abort_unless($quiz->isQuiz(), 404);
    }

    private function assertQuestion(Form $quiz, FormField $field): void
    {
        abort_unless((int) $field->form_id === (int) $quiz->id && $field->isQuestion(), 404);
    }

    private function assertAttempt(Form $quiz, QuizAttempt $attempt): void
    {
        abort_unless((int) $attempt->form_id === (int) $quiz->id, 404);
    }
}

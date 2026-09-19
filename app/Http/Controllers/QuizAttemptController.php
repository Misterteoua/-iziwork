<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Support\QuizDraw;
use App\Support\QuizGrader;
use App\Support\QuizReference;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Passage d'une évaluation par un étudiant.
 *
 * Trois principes tiennent tout le reste :
 *   1. le temps est décidé par le serveur (expires_at figée au démarrage) ;
 *   2. la navigation est linéaire par construction — la question courante est
 *      la première sans réponse, donc rien à « interdire », il n'y a pas de
 *      retour possible ;
 *   3. la correction n'a lieu qu'à la fin, côté serveur : les bonnes réponses
 *      ne sont jamais envoyées au navigateur.
 */
class QuizAttemptController extends Controller
{
    public function __construct(private readonly QuizGrader $grader) {}

    /**
     * Page d'accès : saisie de la référence (mode liste) ou identification
     * (mode libre).
     */
    public function start(Form $quiz)
    {
        $this->assertQuiz($quiz);

        // Une épreuve déjà commencée sur ce navigateur reprend directement.
        $attempt = $this->sessionAttempt($quiz);

        if ($attempt !== null && $attempt->isInProgress() && ! $attempt->hasExpired()) {
            return redirect()->route('quiz.question', $quiz->token);
        }

        // Copie déjà rendue : le bouton « retour » du navigateur ne doit pas
        // ramener le candidat sur un formulaire d'accès qui lui proposerait de
        // recommencer une épreuve terminée.
        if ($attempt !== null && $attempt->isFinished()) {
            return redirect()->route('quiz.result', $quiz->token);
        }

        $questionsCount = $quiz->quizQuestions()->count();

        return view('student.quiz.start', [
            'quiz' => $quiz,
            'usesReferences' => $quiz->quizHasPreparedReferences(),
            'questionsCount' => $questionsCount,
            'maxScore' => $quiz->quizMaxScore(),
        ]);
    }

    /**
     * Démarre (ou reprend) l'épreuve.
     */
    public function begin(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        if (! $quiz->quizIsOpen()) {
            return back()->with('error', 'Cette évaluation n\'est pas ouverte actuellement.');
        }

        $attempt = $this->resumableAttempt($request, $quiz);

        // Reprise : un rechargement de page ne doit pas consommer une nouvelle
        // participation ni remettre le chrono à zéro.
        if ($attempt !== null && $attempt->isInProgress() && ! $attempt->hasExpired()) {
            return redirect()->route('quiz.question', $quiz->token);
        }

        if ($attempt !== null && $attempt->isFinished()) {
            return redirect()->route('quiz.result', $quiz->token);
        }

        // Mode « liste » dès qu'une référence est soumise : une référence
        // inconnue ou mal formée doit être refusée même sur une évaluation dont
        // l'administration n'a pas encore chargé de liste. Sans cette condition,
        // une référence saisie au hasard créerait une participation en mode
        // libre, avec une nouvelle référence — l'étudiant croirait être entré.
        $attempt = $request->filled('reference') || $quiz->quizHasPreparedReferences()
            ? $this->beginWithReference($request, $quiz)
            : $this->beginFree($request, $quiz);

        return $this->startTimer($quiz, $attempt);
    }

    /**
     * La question en cours : la première sans réponse.
     */
    public function question(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->requireRunningAttempt($quiz);

        if (! $attempt instanceof QuizAttempt) {
            return $attempt;
        }

        $questions = $attempt->questions();
        $answered = $attempt->answers()->pluck('form_field_id')->all();

        $position = null;
        $current = null;

        foreach ($questions as $index => $question) {
            if (! in_array($question->id, $answered, false)) {
                $position = $index;
                $current = $question;
                break;
            }
        }

        if ($current === null) {
            return redirect()->route('quiz.submit.page', $quiz->token);
        }

        return view('student.quiz.question', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'question' => $current,
            // Propositions dans l'ordre de ce candidat. Le formulaire envoie
            // l'index d'origine de la proposition, donc la correction ne dépend
            // pas du mélange reçu.
            'options' => $attempt->displayOptions($current),
            'position' => $position + 1,
            'total' => $questions->count(),
            'remaining' => $attempt->remainingSeconds(),
        ]);
    }

    /**
     * Enregistre la réponse à la question en cours.
     */
    public function answer(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->requireRunningAttempt($quiz);

        if (! $attempt instanceof QuizAttempt) {
            return $attempt;
        }

        $question = $attempt->questions()
            ->firstWhere('id', (int) $request->input('question_id'));

        if ($question === null) {
            abort(404);
        }

        // Contrôle de linéarité côté serveur : on refuse de répondre à une
        // question s'il reste une question sans réponse avant elle. Masquer un
        // bouton ne suffit pas, un formulaire peut être renvoyé à la main.
        if ($this->hasUnansweredQuestionBefore($attempt, $question)) {
            return redirect()->route('quiz.question', $quiz->token)
                ->with('error', 'Répondez aux questions dans l\'ordre.');
        }

        // Une question validée est définitive : sans ce refus, un renvoi manuel
        // du formulaire permettrait de revenir corriger une réponse —
        // exactement le retour en arrière que l'évaluation interdit.
        if ($attempt->answers()->where('form_field_id', $question->id)->exists()) {
            return redirect()->route('quiz.question', $quiz->token)
                ->with('error', 'Cette question a déjà été validée.');
        }

        $chosen = $this->validatedChoice($request, $question);

        QuizAnswer::updateOrCreate(
            ['quiz_attempt_id' => $attempt->id, 'form_field_id' => $question->id],
            ['choice' => $chosen, 'answered_at' => Carbon::now()]
        );

        return redirect()->route('quiz.question', $quiz->token);
    }

    /**
     * Page de fin : confirmation avant correction définitive.
     */
    public function submitPage(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->requireRunningAttempt($quiz);

        if (! $attempt instanceof QuizAttempt) {
            return $attempt;
        }

        return view('student.quiz.finish', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'answered' => $attempt->answers()->count(),
            'total' => $attempt->questions()->count(),
            'remaining' => $attempt->remainingSeconds(),
        ]);
    }

    /**
     * Correction définitive, déclenchée par l'étudiant ou par la fin du temps.
     */
    public function submit(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->sessionAttempt($quiz);

        if ($attempt === null) {
            return redirect()->route('quiz.start', $quiz->token);
        }

        if ($attempt->isFinished()) {
            return redirect()->route('quiz.result', $quiz->token);
        }

        $this->grader->finalize($attempt, $attempt->hasExpired());

        return redirect()->route('quiz.result', $quiz->token);
    }

    public function result(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->sessionAttempt($quiz);

        if ($attempt === null) {
            return redirect()->route('quiz.start', $quiz->token);
        }

        if (! $attempt->isFinished()) {
            return redirect()->route('quiz.question', $quiz->token);
        }

        return view('student.quiz.result', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'answers' => $attempt->answers()->with('field')->get(),
            'questions' => $attempt->questions(),
            'showScore' => $quiz->quizShowsScore(),
        ]);
    }

    /**
     * Récapitulatif PDF : note, référence, temps utilisé.
     *
     * La référence sert de clé d'accès, exactement comme le jeton de reçu d'un
     * dépôt : connaissant les dix caractères, l'étudiant peut retélécharger son
     * bulletin sans dépendre d'une session de navigateur.
     */
    public function recapPdf(Form $quiz, string $reference)
    {
        $this->assertQuiz($quiz);

        $normalized = QuizReference::normalize($reference);
        abort_if($normalized === null, 404);

        $attempt = $quiz->attempts()->where('reference', $normalized)->firstOrFail();

        abort_unless($attempt->isFinished(), 404);

        $attempt->load('answers');

        $pdf = Pdf::loadView('student.quiz.recap-pdf', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'showScore' => $quiz->quizShowsScore(),
        ]);

        return $pdf->download('evaluation_'.$attempt->reference.'.pdf');
    }

    /**
     * Journalise une perte de focus signalée par la page (changement d'onglet,
     * sortie de plein écran). Trace, jamais de sanction automatique.
     */
    public function infraction(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->sessionAttempt($quiz);

        if ($attempt === null || ! $attempt->isInProgress()) {
            return response()->json(['recorded' => false]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['tab_hidden', 'window_blur', 'fullscreen_exit', 'copy_attempt'])],
            'detail' => ['nullable', 'string', 'max:255'],
        ]);

        $attempt->recordInfraction($validated['type'], $validated['detail'] ?? null);

        return response()->json([
            'recorded' => true,
            'count' => $attempt->infraction_count,
            'message' => 'Changement de fenêtre enregistré. Cette évaluation est surveillée.',
        ]);
    }

    // ---------------------------------------------------------------- Interne

    /**
     * Ouvre une participation à partir d'une référence préparée.
     */
    private function beginWithReference(Request $request, Form $quiz): QuizAttempt
    {
        $request->validate([
            'reference' => ['required', 'string'],
        ], [
            'reference.required' => 'Saisissez la référence reçue pour cette évaluation.',
        ]);

        $reference = QuizReference::normalize($request->input('reference'));

        if ($reference === null) {
            throw ValidationException::withMessages([
                'reference' => 'Une référence comporte 10 caractères (lettres et chiffres).',
            ]);
        }

        $attempt = $quiz->attempts()->where('reference', $reference)->first();

        if ($attempt === null) {
            throw ValidationException::withMessages([
                'reference' => 'Cette référence n\'est pas reconnue pour cette évaluation.',
            ]);
        }

        if ($attempt->isFinished()) {
            throw ValidationException::withMessages([
                'reference' => 'Cette référence a déjà servi : l\'évaluation a été rendue.',
            ]);
        }

        if ($attempt->isInProgress()) {
            // Reprise après coupure : le chrono d'origine continue de courir.
            return $attempt;
        }

        if (! $quiz->is_anonymous) {
            // Nom déjà fourni par la liste importée : on ne le redemande pas et
            // on ne l'écrase pas, sinon la liste de distribution ne correspondrait
            // plus à ce qui est enregistré.
            if ($attempt->student_name !== null) {
                return $attempt;
            }

            $request->validate([
                'student_name' => ['required', 'string', 'max:255'],
                'student_email' => ['nullable', 'email', 'max:255'],
                'student_major' => ['nullable', 'string', 'max:255'],
            ], [
                'student_name.required' => 'Indiquez votre nom complet.',
                'student_email.email' => 'L\'adresse email saisie n\'est pas valide.',
            ]);

            $attempt->update([
                'student_name' => trim((string) $request->input('student_name')),
                'student_email' => $request->filled('student_email')
                    ? mb_strtolower(trim((string) $request->input('student_email')))
                    : null,
                'student_major' => $request->filled('student_major')
                    ? trim((string) $request->input('student_major'))
                    : null,
            ]);
        }

        return $attempt;
    }

    /**
     * Mode libre : l'étudiant s'identifie, une référence lui est attribuée.
     */
    private function beginFree(Request $request, Form $quiz): QuizAttempt
    {
        if (! $quiz->is_anonymous) {
            $request->validate([
                'student_name' => ['required', 'string', 'max:255'],
                'student_email' => ['nullable', 'email', 'max:255'],
                'student_major' => ['nullable', 'string', 'max:255'],
            ], [
                'student_name.required' => 'Indiquez votre nom complet.',
                'student_email.email' => 'L\'adresse email saisie n\'est pas valide.',
            ]);
        }

        return $quiz->attempts()->create([
            'reference' => QuizReference::generate(),
            // Évaluation anonyme : aucun nom n'est stocké, même si l'étudiant en
            // propose un. L'anonymat doit tenir côté base, pas côté affichage.
            'student_name' => $quiz->is_anonymous ? null : trim((string) $request->input('student_name')),
            'student_email' => $quiz->is_anonymous || ! $request->filled('student_email')
                ? null
                : mb_strtolower(trim((string) $request->input('student_email'))),
            'student_major' => $quiz->is_anonymous || ! $request->filled('student_major')
                ? null
                : trim((string) $request->input('student_major')),
        ]);
    }

    /**
     * Fixe le chrono et ouvre la session du navigateur.
     */
    private function startTimer(Form $quiz, QuizAttempt $attempt)
    {
        if ($attempt->started_at === null) {
            // Le tirage est calculé ici, une fois pour toutes : quelles questions,
            // dans quel ordre, et l'ordre des propositions. Un plan recalculé à
            // chaque page donnerait une épreuve différente à chaque rechargement.
            $plan = QuizDraw::plan($quiz);

            $attempt->update([
                'status' => QuizAttempt::STATUS_IN_PROGRESS,
                'started_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes($quiz->quizDurationMinutes()),
                'ip_address' => request()->ip(),
                'question_order' => $plan['question_order'] === [] ? null : $plan['question_order'],
                'option_order' => $plan['option_order'] === [] ? null : $plan['option_order'],
                'max_score' => $plan['max_score'],
            ]);
        }

        session(['quiz_attempt.'.$quiz->id => $attempt->id]);

        return redirect()->route('quiz.question', $quiz->token);
    }

    /**
     * Tente de récupérer la participation en cours, en vérifiant le chrono.
     *
     * Retourne une redirection quand l'épreuve doit s'arrêter (temps écoulé,
     * session absente) : l'appelant renvoie directement cette réponse.
     */
    private function requireRunningAttempt(Form $quiz): QuizAttempt|RedirectResponse
    {
        $attempt = $this->sessionAttempt($quiz);

        if ($attempt === null) {
            return redirect()->route('quiz.start', $quiz->token)
                ->with('error', 'Saisissez de nouveau votre référence pour continuer.');
        }

        if ($attempt->isFinished()) {
            return redirect()->route('quiz.result', $quiz->token);
        }

        // Chrono écoulé : on corrige ce qui a été répondu et on clôt.
        if ($attempt->hasExpired()) {
            $this->grader->finalize($attempt, expired: true);

            return redirect()->route('quiz.result', $quiz->token)
                ->with('error', 'Le temps imparti est écoulé : l\'évaluation a été rendue automatiquement.');
        }

        return $attempt;
    }

    /**
     * Participation à reprendre sur ce navigateur, ou null.
     *
     * Une référence saisie qui n'est pas celle de la session signifie qu'un autre
     * candidat utilise le même ordinateur (salle informatique, poste partagé) :
     * il ne doit pas être déposé dans la copie du précédent, sinon ses réponses
     * seraient enregistrées sous la référence d'un autre.
     */
    private function resumableAttempt(Request $request, Form $quiz): ?QuizAttempt
    {
        $attempt = $this->sessionAttempt($quiz);

        if ($attempt === null) {
            return null;
        }

        $reference = QuizReference::normalize($request->input('reference'));

        return $reference === null || $reference === $attempt->reference ? $attempt : null;
    }

    private function sessionAttempt(Form $quiz): ?QuizAttempt
    {
        $id = session('quiz_attempt.'.$quiz->id);

        if ($id === null) {
            return null;
        }

        return $quiz->attempts()->whereKey($id)->first();
    }

    /**
     * Une question sans réponse précède-t-elle celle-ci ?
     */
    private function hasUnansweredQuestionBefore(QuizAttempt $attempt, FormField $question): bool
    {
        $answered = $attempt->answers()->pluck('form_field_id')->all();

        foreach ($attempt->questions() as $candidate) {
            if ($candidate->id === $question->id) {
                return false;
            }

            if (! in_array($candidate->id, $answered, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valide la réponse envoyée et la normalise en liste d'index triés.
     *
     * @return array<int, int>
     */
    private function validatedChoice(Request $request, FormField $question): array
    {
        $rule = $question->isMultipleAnswer()
            ? ['required', 'array', 'min:1']
            : ['required'];

        $request->validate([
            'choice' => $rule,
            'choice.*' => ['integer', 'min:0'],
        ], [
            'choice.required' => 'Sélectionnez une réponse avant de continuer.',
            'choice.min' => 'Sélectionnez au moins une réponse avant de continuer.',
        ]);

        $raw = $request->input('choice');
        $indexes = array_map('intval', is_array($raw) ? $raw : [$raw]);

        $optionsCount = count($question->getOptionsList());

        foreach ($indexes as $index) {
            if ($index < 0 || $index >= $optionsCount) {
                throw ValidationException::withMessages([
                    'choice' => 'La réponse sélectionnée est invalide.',
                ]);
            }
        }

        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return $indexes;
    }

    private function assertQuiz(Form $quiz): void
    {
        abort_unless($quiz->isQuiz(), 404);
    }
}

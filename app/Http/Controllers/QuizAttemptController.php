<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\Qr\QrPng;
use App\Support\QuizDraw;
use App\Support\QuizGrader;
use App\Support\QuizQuestionData;
use App\Support\QuizReference;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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

        $questionsCount = $quiz->quizQuestions()->count();

        // Copie déjà rendue sur cet appareil : le formulaire reste affiché, parce
        // que le poste doit pouvoir servir au candidat suivant (salle
        // informatique). Elle est rappelée par un bandeau, qui mène à son
        // résultat — la page ne renvoie plus directement à la copie du précédent.
        return view('student.quiz.start', [
            'quiz' => $quiz,
            'usesReferences' => $quiz->quizHasPreparedReferences(),
            'questionsCount' => $questionsCount,
            'maxScore' => $quiz->quizMaxScore(),
            'finishedAttempt' => $attempt !== null && $attempt->isFinished() ? $attempt : null,
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

        $session = $this->sessionAttempt($quiz);

        // Reprise : un rechargement de page ne doit pas consommer une nouvelle
        // participation ni remettre le chrono à zéro.
        $resumable = $this->resumableAttempt($request, $session);

        if ($resumable !== null && $resumable->isInProgress() && ! $resumable->hasExpired()) {
            return redirect()->route('quiz.question', $quiz->token);
        }

        // Même candidat qui ressaisit sa propre référence sur sa copie rendue :
        // c'est la même personne, on lui montre son résultat. Un candidat
        // différent passe par la suite — sa référence, elle, n'a pas encore servi.
        if ($session !== null && $session->isFinished() && $this->postsOwnReference($request, $session)) {
            return redirect()->route('quiz.result', $quiz->token);
        }

        // Mode « liste » dès qu'une référence est soumise : une référence
        // inconnue ou mal formée doit être refusée même sur une évaluation dont
        // l'administration n'a pas encore chargé de liste. Sans cette condition,
        // une référence saisie au hasard créerait une participation en mode
        // libre, avec une nouvelle référence — l'étudiant croirait être entré.
        // Le poste est au candidat qui se présente : sur une copie rendue, une
        // nouvelle saisie de nom (mode libre) ouvre une copie à lui, et une
        // référence déjà servie est refusée par beginWithReference().
        $attempt = $request->filled('reference') || $quiz->quizHasPreparedReferences()
            ? $this->beginWithReference($request, $quiz)
            : $this->beginFree($request, $quiz);

        // Le choix du plein écran est enregistré avec l'épreuve : c'est lui qui
        // décide du libellé du bouton sur la page de question. Rien n'est forcé
        // pour autant — le clic reste nécessaire, et le candidat peut changer
        // d'avis à tout moment.
        session(['quiz_fullscreen.'.$quiz->id => $request->boolean('fullscreen')]);

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

        $current = $this->currentQuestion($attempt);

        if ($current['question'] === null) {
            return redirect()->route('quiz.submit.page', $quiz->token);
        }

        return view('student.quiz.question', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'fullscreenPreferred' => $this->fullscreenPreferred($quiz),
            'question' => $current['question'],
            // Propositions dans l'ordre de ce candidat. Le formulaire envoie
            // l'index d'origine de la proposition, donc la correction ne dépend
            // pas du mélange reçu.
            'options' => $attempt->displayOptions($current['question']),
            'position' => $current['position'],
            'total' => $current['total'],
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
            // Épreuve terminée, expirée ou session perdue : le navigateur doit
            // aller voir la page concernée, pas recevoir du HTML à insérer.
            return $request->wantsJson()
                ? response()->json(['ok' => false, 'navigate' => $attempt->getTargetUrl()])
                : $attempt;
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
            // La question affichée est déjà la bonne : ne pas la remplacer
            // préserve la réponse que le candidat vient de saisir.
            return $this->answerResponse($request, $quiz, $attempt, 'Répondez aux questions dans l\'ordre.');
        }

        // Une question validée est définitive : sans ce refus, un renvoi manuel
        // du formulaire permettrait de revenir corriger une réponse —
        // exactement le retour en arrière que l'évaluation interdit.
        if ($attempt->answers()->where('form_field_id', $question->id)->exists()) {
            // Ici, en revanche, la carte affichée est périmée : la remplacer
            // évite de laisser le candidat devant une question qui ne reviendra
            // jamais.
            return $this->answerResponse($request, $quiz, $attempt, 'Cette question a déjà été validée.', replace: true);
        }

        // Deux natures de réponse, un seul enregistrement : une question ouverte
        // garde le texte tapé, une question à propositions garde les index cochés.
        if ($question->isOpen()) {
            QuizAnswer::updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'form_field_id' => $question->id],
                [
                    'answer_text' => $this->validatedOpenAnswer($request),
                    'choice' => null,
                    'answered_at' => Carbon::now(),
                ]
            );

            return $this->answerResponse($request, $quiz, $attempt);
        }

        $chosen = $this->validatedChoice($request, $question);

        QuizAnswer::updateOrCreate(
            ['quiz_attempt_id' => $attempt->id, 'form_field_id' => $question->id],
            ['choice' => $chosen, 'answer_text' => null, 'answered_at' => Carbon::now()]
        );

        return $this->answerResponse($request, $quiz, $attempt);
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
            'fullscreenPreferred' => $this->fullscreenPreferred($quiz),
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

        return $this->renderResult($quiz, $attempt);
    }

    /**
     * La page de résultat, partagée par la session du navigateur et par le lien
     * court de l'étudiant (/l/{code}) : une seule source de vérité, donc un lien
     * de suivi qui montre toujours exactement ce que montre la page.
     *
     * `$viaShortLink` masque ce qui n'a de sens que sur le poste de l'étudiant :
     * depuis un lien, il n'y a pas de session à détacher.
     */
    public function renderResult(Form $quiz, QuizAttempt $attempt, bool $viaShortLink = false): View
    {
        // Le lien de suivi de l'étudiant : créé à la première consultation, puis
        // toujours le même. C'est lui qu'il garde pour revenir voir sa note
        // définitive, une fois les questions rédigées corrigées.
        $followLink = ShortLink::forAttempt($quiz, $attempt);

        return view('student.quiz.result', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'answers' => $attempt->answers()->with('field')->get(),
            'questions' => $attempt->questions(),
            'showScore' => $quiz->quizShowsScore(),
            // Réponses rédigées encore à corriger : la note n'est alors qu'une
            // note provisoire, et l'étudiant doit le lire noir sur blanc.
            'pending' => $attempt->pendingManualCount(),
            'viaShortLink' => $viaShortLink,
            'followLink' => $followLink,
            // Le QR est calculé côté serveur : il s'affiche sans JavaScript et
            // se retrouve tel quel dans le récapitulatif PDF.
            'followQr' => QrPng::dataUri($followLink->url()),
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

        $followLink = ShortLink::forAttempt($quiz, $attempt);

        $pdf = Pdf::loadView('student.quiz.recap-pdf', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'showScore' => $quiz->quizShowsScore(),
            'pending' => $attempt->pendingManualCount(),
            // Le lien de suivi figure dans le document que l'étudiant garde :
            // c'est ce qui lui permet de revenir voir sa note définitive.
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
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

        $recorded = $attempt->recordInfraction($validated['type'], $validated['detail'] ?? null);

        // Un doublon est acquitté sans être écrit : la page n'a pas à savoir
        // pourquoi, mais le compteur qu'elle affiche reste celui du serveur.
        return response()->json([
            'recorded' => $recorded,
            'count' => $attempt->infraction_count,
            'message' => $recorded ? QuizAttempt::infractionMessage($validated['type']) : null,
        ]);
    }

    // ---------------------------------------------------------------- Interne

    /**
     * La question en cours et sa place : la première sans réponse.
     *
     * Une seule définition pour la page complète et pour la réponse JSON. Deux
     * calculs séparés finiraient par diverger, et le candidat verrait alors deux
     * questions différentes selon que JavaScript répond ou non.
     *
     * @return array{question: ?FormField, position: int, total: int}
     */
    /**
     * Le candidat a-t-il demandé le plein écran en commençant l'épreuve ?
     *
     * La préférence est gardée en session plutôt que dans le navigateur : elle
     * survit ainsi à un rechargement, à un changement de page, et à l'ouverture
     * de l'épreuve dans un autre onglet — trois cas où un stockage local la
     * laisserait tomber sans rien dire.
     */
    private function fullscreenPreferred(Form $quiz): bool
    {
        return (bool) session('quiz_fullscreen.'.$quiz->id, false);
    }

    private function currentQuestion(QuizAttempt $attempt): array
    {
        $questions = $attempt->questions();
        $answered = $attempt->answers()->pluck('form_field_id')->all();

        foreach ($questions as $index => $question) {
            if (! in_array($question->id, $answered, false)) {
                return [
                    'question' => $question,
                    'position' => $index + 1,
                    'total' => $questions->count(),
                ];
            }
        }

        return ['question' => null, 'position' => 0, 'total' => $questions->count()];
    }

    /**
     * Réponse à un envoi de réponse : redirection classique, ou fragment JSON.
     *
     * La redirection reste le chemin par défaut — c'est elle qui fonctionne sans
     * JavaScript, et c'est elle que couvrent les tests existants. Le fragment
     * n'est produit que lorsque le navigateur le demande : il permet de remplacer
     * la carte de la question sans recharger la page, et donc de conserver le
     * plein écran entre deux questions — ce qu'aucune redirection ne peut faire,
     * le plein écran appartenant au document.
     */
    private function answerResponse(
        Request $request,
        Form $quiz,
        QuizAttempt $attempt,
        ?string $error = null,
        bool $replace = false,
    ): RedirectResponse|JsonResponse {
        if (! $request->wantsJson()) {
            $redirect = redirect()->route('quiz.question', $quiz->token);

            return $error === null ? $redirect : $redirect->with('error', $error);
        }

        $current = $this->currentQuestion($attempt);

        // Plus rien à répondre : la page de confirmation prend le relais.
        if ($current['question'] === null) {
            return response()->json([
                'ok' => true,
                'error' => $error,
                'replace' => false,
                'navigate' => route('quiz.submit.page', $quiz->token),
            ]);
        }

        return response()->json([
            'ok' => true,
            'error' => $error,
            'replace' => $replace,
            'html' => view('student.quiz._question_card', [
                'quiz' => $quiz,
                'question' => $current['question'],
                'options' => $attempt->displayOptions($current['question']),
                'position' => $current['position'],
                'total' => $current['total'],
            ])->render(),
            'position' => $current['position'],
            'total' => $current['total'],
            // Le chronomètre affiché est recalé sur le serveur à chaque réponse.
            'remaining' => $attempt->remainingSeconds(),
        ]);
    }

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
    private function resumableAttempt(Request $request, ?QuizAttempt $session): ?QuizAttempt
    {
        if ($session === null) {
            return null;
        }

        $reference = QuizReference::normalize($request->input('reference'));

        return $reference === null || $reference === $session->reference ? $session : null;
    }

    /**
     * Le candidat a-t-il ressaisi la référence de sa propre copie ?
     *
     * Sert uniquement à le renvoyer vers son résultat après une copie rendue :
     * sa référence, elle, a déjà servi et ne peut plus ouvrir d'épreuve.
     */
    private function postsOwnReference(Request $request, QuizAttempt $session): bool
    {
        $reference = QuizReference::normalize($request->input('reference'));

        return $reference !== null && $reference === $session->reference;
    }

    /**
     * Détache le poste de la copie qu'il vient de rendre.
     *
     * Salle informatique : le candidat suivant doit pouvoir commencer sans être
     * renvoyé sur le résultat du précédent. Seule une copie **rendue** peut être
     * détachée — abandonner une épreuve en cours ferait perdre à son auteur le
     * fil de sa propre copie, et personne d'autre ne peut le décider à sa place.
     */
    public function newCandidate(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->sessionAttempt($quiz);

        if ($attempt !== null && ! $attempt->isFinished()) {
            return redirect()->route('quiz.question', $quiz->token)
                ->with('error', 'Cette épreuve est en cours : terminez-la ou laissez le temps s\'écouler avant de laisser la place à un autre étudiant.');
        }

        session()->forget('quiz_attempt.'.$quiz->id);

        return redirect()->route('quiz.start', $quiz->token);
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
     * Valide une réponse rédigée.
     *
     * Un texte fait uniquement d'espaces est refusé : la règle `required` de
     * Laravel accepte «   » comme une chaîne non vide, ce qui laisserait passer
     * une question ouverte « répondue » sans un mot.
     */
    private function validatedOpenAnswer(Request $request): string
    {
        $request->validate([
            'answer_text' => ['required', 'string', 'max:'.QuizQuestionData::MAX_STUDENT_ANSWER],
        ], [
            'answer_text.required' => 'Rédigez votre réponse avant de continuer.',
            'answer_text.max' => 'Votre réponse ne peut pas dépasser '.QuizQuestionData::MAX_STUDENT_ANSWER.' caractères.',
        ]);

        $text = trim((string) $request->input('answer_text'));

        if ($text === '') {
            throw ValidationException::withMessages([
                'answer_text' => 'Rédigez votre réponse avant de continuer.',
            ]);
        }

        // Un navigateur envoie de l'UTF-8, mais une requête forgée peut porter une
        // suite d'octets invalide — et MySQL en utf8mb4 strict refuse alors
        // l'insertion : la copie serait perdue au moment du rendu, avec une erreur
        // 500 en pleine épreuve. On remplace les octets fautifs plutôt que de
        // laisser une réponse honnête faire échouer l'enregistrement.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return $text;
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

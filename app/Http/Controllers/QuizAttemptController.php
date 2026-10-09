<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAnswer;
use App\Models\QuizAttachment;
use App\Models\QuizAttempt;
use App\Models\ShortLink;
use App\Support\PdfWatermark;
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
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    /**
     * Le nom du cookie qui identifie l'appareil, et non la personne.
     *
     * Il est chiffré par le framework (aucun contenu lisible côté navigateur) et
     * ne sert qu'à une chose : savoir qu'une copie a déjà été remise depuis ce
     * poste, quand l'évaluation est anonyme et que ni le nom ni l'adresse email
     * ne peuvent le dire.
     */
    private const DEVICE_COOKIE = 'iziwork_device';

    /** Durée de vie de l'empreinte : un an, soit une année universitaire large. */
    private const DEVICE_COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * Pièces jointes acceptées en réponse à une question rédigée.
     *
     * Une image ou un PDF : de quoi photographier une copie manuscrite ou
     * joindre un document. La liste est blanche et vérifiée par extension ET par
     * type MIME — un fichier renommé ne passe donc pas.
     */
    private const ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** Taille maximale d'une pièce jointe : 1 Mo (en kilo-octets, pour Laravel). */
    private const ATTACHMENT_MAX_KB = 1024;

    /** Nombre maximal de pièces jointes par question. */
    private const ATTACHMENT_MAX_PER_QUESTION = 2;

    /**
     * Les seuls statuts qui pèsent dans l'anti-doublon : une copie engagée.
     *
     * Une ligne « non commencée » n'est pas une soumission — c'est une référence
     * réservée par la liste importée, ou une place restée libre.
     */
    private const ENGAGED_STATUSES = [
        QuizAttempt::STATUS_IN_PROGRESS,
        QuizAttempt::STATUS_SUBMITTED,
        QuizAttempt::STATUS_EXPIRED,
    ];

    public function __construct(private readonly QuizGrader $grader) {}

    /**
     * Page d'accès : saisie de la référence (mode liste) ou identification
     * (mode libre).
     */
    public function start(Form $quiz)
    {
        $this->assertQuiz($quiz);

        // L'empreinte de l'appareil est posée dès la page d'accès, et non au
        // démarrage : elle existe donc avant le premier envoi de formulaire, ce
        // qui permet de contrôler un doublon sans jamais créer de copie fantôme.
        $this->deviceToken();

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
            // Ouverture à venir : la page affiche le temps restant et se
            // rafraîchit d'elle-même à l'heure dite, pour que le formulaire
            // d'accès apparaisse sans que personne n'ait à y penser. Null dans
            // tous les autres cas de fermeture — et le refus de démarrer avant
            // l'heure, lui, reste côté serveur (voir begin()).
            'opensInSeconds' => $quiz->quizOpensInSeconds(),
            'opensAt' => $quiz->quizOpensAt(),
            // Ce que la salle d'attente a retenu (voir prepare()) : le formulaire
            // s'affiche donc déjà rempli, et le candidat n'a rien à retaper au
            // moment précis où le chronomètre démarre.
            'prepared' => $this->waitingValues($quiz),
            // Réglage d'évaluation : une épreuve peut retirer le signal sonore
            // de la page d'attente (voir Form::quizPlaysOpeningSound()).
            'playsOpeningSound' => $quiz->quizPlaysOpeningSound(),
            'preview' => false,
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

        // Anti-doublon par appareil : contrôlé ici, avant toute création, pour ne
        // jamais laisser derrière soi une copie fantôme. Le réglage est propre à
        // l'évaluation et désactivé par défaut — une salle informatique partage
        // ses postes, et le candidat suivant doit pouvoir commencer.
        //
        // Seules les copies **remises** comptent : une épreuve en cours n'est pas
        // un doublon, et son auteur doit pouvoir la reprendre même si sa session
        // de navigateur s'est perdue entre-temps.
        $device = $this->deviceToken();

        if ($this->deviceAlreadySubmitted($quiz, $device)) {
            return back()->with('error', 'Une copie a déjà été remise depuis cet appareil pour cette évaluation : une seule copie par appareil est acceptée.');
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

        // L'épreuve est engagée : ce que la salle d'attente avait retenu a servi.
        // Le garder plus longtemps ferait apparaître le nom du candidat précédent
        // dans le formulaire du suivant — et une salle informatique partage ses
        // postes, c'est précisément le cas à ne pas manquer.
        session()->forget($this->waitingKey($quiz));

        return $this->startTimer($quiz, $attempt, $device);
    }

    /**
     * Salle d'attente : retient le nom et la référence, sans rien créer.
     *
     * Une épreuve qui n'a pas encore ouvert ne peut pas enregistrer de
     * participation — ce serait une copie ouverte avant l'heure. La page
     * d'attente recueille donc seulement ces valeurs en session, et la page
     * d'accès les retrouve pré-remplies une fois l'heure venue.
     *
     * La référence est contrôlée **dans sa forme, jamais dans son existence**.
     * Tant que l'épreuve est fermée, cette page ne doit pas permettre de savoir
     * quelles références ont été distribuées : c'est beginWithReference() qui en
     * décide, à l'ouverture, et lui seul. Une liste pas encore chargée ne bloque
     * donc personne pendant l'attente.
     */
    public function prepare(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $request->validate([
            'reference' => ['nullable', 'string'],
            'student_name' => ['nullable', 'string', 'max:255'],
            'student_email' => ['nullable', 'email', 'max:255'],
            'student_major' => ['nullable', 'string', 'max:255'],
        ], [
            'student_email.email' => 'L\'adresse email saisie n\'est pas valide.',
        ]);

        $reference = QuizReference::normalize($request->input('reference'));

        if ($request->filled('reference') && $reference === null) {
            throw ValidationException::withMessages([
                'reference' => 'Une référence comporte 10 caractères (lettres et chiffres).',
            ]);
        }

        session([$this->waitingKey($quiz) => $this->waitingPayload($request, $quiz, $reference)]);

        return redirect()->route('quiz.start', $quiz->token)
            ->with('success', 'Vos informations sont retenues : à l\'ouverture, le formulaire sera déjà rempli.');
    }

    /**
     * Ce qui est retenu pour l'ouverture : jamais de clé vide, et aucun nom sur
     * une évaluation anonyme.
     *
     * La règle de l'anonymat est celle de beginFree() : la salle d'attente ne
     * doit pas conserver ce que l'épreuve refusera d'enregistrer — et la page
     * d'accès ne propose d'ailleurs pas ces champs.
     *
     * @return array<string, string>
     */
    private function waitingPayload(Request $request, Form $quiz, ?string $reference): array
    {
        $values = ['reference' => $reference];

        if (! $quiz->is_anonymous) {
            $values += [
                'student_name' => $request->filled('student_name')
                    ? trim((string) $request->input('student_name'))
                    : null,
                'student_email' => $this->normalizedEmail($request),
                'student_major' => $request->filled('student_major')
                    ? trim((string) $request->input('student_major'))
                    : null,
            ];
        }

        return array_filter($values, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Ce que la salle d'attente a retenu pour cette évaluation, ou rien.
     *
     * La clé est propre à l'évaluation : deux épreuves ouvertes dans le même
     * navigateur ne se remplissent pas l'une l'autre.
     *
     * @return array<string, string>
     */
    private function waitingValues(Form $quiz): array
    {
        $values = session($this->waitingKey($quiz), []);

        return is_array($values) ? $values : [];
    }

    private function waitingKey(Form $quiz): string
    {
        return 'quiz_waiting.'.$quiz->id;
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
        // garde le texte tapé (et ses éventuelles pièces jointes), une question à
        // propositions garde les index cochés.
        if ($question->isOpen()) {
            $text = $this->validatedOpenAnswer($request);
            $uploads = $this->validatedAttachments($request);

            // Une question ouverte se répond par un texte, par une pièce jointe,
            // ou par les deux : exiger un texte priverait d'une réponse honnête
            // l'étudiant qui rend une photo de sa copie manuscrite.
            if ($text === null && $uploads === []) {
                throw ValidationException::withMessages([
                    'answer_text' => 'Rédigez votre réponse ou joignez un document avant de continuer.',
                ]);
            }

            QuizAnswer::updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'form_field_id' => $question->id],
                [
                    'answer_text' => $text,
                    'choice' => null,
                    'answered_at' => Carbon::now(),
                ]
            );

            $this->storeAttachments($attempt, $question, $uploads);

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

        // Le navigateur rend la copie de lui-même à l'échéance (voir le
        // formulaire d'expiration) : le dire, plutôt que de laisser croire à une
        // remise volontaire. C'est justement le moment où l'étudiant doit
        // comprendre pourquoi il n'a pas pu finir.
        if ($attempt->hasExpired()) {
            return $this->closeAtDeadline($quiz, $attempt);
        }

        $this->grader->finalize($attempt);

        return redirect()->route('quiz.result', $quiz->token);
    }

    /**
     * Rend la copie à l'échéance, et le dit.
     *
     * Chemin unique pour les deux façons d'y arriver : la requête suivante du
     * candidat (rechargement, question suivante) et la soumission que le
     * navigateur déclenche de lui-même. La correction emporte les réponses déjà
     * validées ; une copie rendue blanche reste blanche, et c'est le rapport de
     * l'enseignant qui la signalera.
     */
    private function closeAtDeadline(Form $quiz, QuizAttempt $attempt): RedirectResponse
    {
        // L'heure de fermeture de l'épreuve n'est pas le chrono du candidat, et
        // il ne lit pas les deux de la même façon.
        $closedByQuiz = $attempt->closedByQuiz();

        $this->grader->finalize($attempt, expired: true);

        return redirect()->route('quiz.result', $quiz->token)
            ->with('error', $closedByQuiz
                ? 'L\'épreuve est fermée : le temps est arrivé à échéance, et votre copie a été rendue automatiquement.'
                : 'Le temps imparti est écoulé : l\'évaluation a été rendue automatiquement.');
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

        // Les pièces jointes de la copie, chargées d'un coup : le détail de la
        // correction les liste question par question, et sans cela chaque
        // question déclencherait sa propre requête.
        $attempt->load('attachments');

        return view('student.quiz.result', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'answers' => $attempt->answers()->with('field')->get(),
            'questions' => $attempt->questions(),
            'showScore' => $quiz->quizShowsScore(),
            // Le détail de la correction n'est publié qu'une fois l'évaluation
            // fermée : tant qu'un autre candidat peut composer, les bonnes
            // réponses ne sortent pas de l'application.
            'revealsCorrection' => $quiz->quizRevealsCorrection(),
            // Prochaine échéance de publication, pour la promettre en clair
            // plutôt que de laisser l'étudiant revenir tous les jours.
            'revealMoment' => $quiz->quizRevealMoment(),
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

        $attempt->load(['answers', 'attachments']);

        $followLink = ShortLink::forAttempt($quiz, $attempt);

        // Numéro de document : stable pour cette copie, dérivé de la clé de
        // l'application et de sa référence. C'est lui qui rend un document
        // recopié d'un autre dossier reconnaissable.
        $documentId = PdfWatermark::documentId('quiz-attempt', $attempt->reference);

        $pdf = Pdf::loadView('student.quiz.recap-pdf', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'showScore' => $quiz->quizShowsScore(),
            // Le PDF est retéléchargeable à volonté avec la seule référence : il
            // obéit donc exactement à la même règle que la page de résultat —
            // échéance annoncée comprise.
            'revealsCorrection' => $quiz->quizRevealsCorrection(),
            'revealMoment' => $quiz->quizRevealMoment(),
            'pending' => $attempt->pendingManualCount(),
            // Le lien de suivi figure dans le document que l'étudiant garde :
            // c'est ce qui lui permet de revenir voir sa note définitive.
            'followLink' => $followLink,
            'followQr' => QrPng::dataUri($followLink->url()),
            'documentId' => $documentId,
        ]);

        PdfWatermark::apply($pdf, $documentId);

        return $pdf->download('evaluation_'.$attempt->reference.'.pdf');
    }

    /**
     * Télécharge une pièce jointe de sa propre copie.
     *
     * L'accès est décidé par la session : c'est la copie en cours de ce
     * navigateur, ou rien. Un identifiant deviné ne mène donc pas au document
     * d'un autre candidat. Depuis un lien de suivi (`/l/{code}`), il n'y a pas
     * de session à confronter : ce chemin-là est réservé à la session normale.
     */
    public function attachment(Request $request, Form $quiz, QuizAttachment $attachment)
    {
        $this->assertQuiz($quiz);

        $attempt = $this->sessionAttempt($quiz);

        abort_unless(
            $attempt !== null && (int) $attachment->quiz_attempt_id === (int) $attempt->id,
            404
        );

        return $this->downloadAttachment($attachment);
    }

    /**
     * Télécharge une pièce jointe à partir de la référence de la copie.
     *
     * Le récapitulatif PDF se télécharge avec la seule référence et porte le nom
     * des pièces jointes : le lien qu'il contient doit donc fonctionner sans la
     * session du navigateur, exactement comme le document lui-même. La référence
     * est la clé, et elle ne vaut que pour cette copie : une pièce étrangère est
     * refusée même avec la bonne référence.
     */
    public function recapAttachment(Request $request, Form $quiz, string $reference, QuizAttachment $attachment)
    {
        $this->assertQuiz($quiz);

        $normalized = QuizReference::normalize($reference);
        abort_if($normalized === null, 404);

        $attempt = $quiz->attempts()->where('reference', $normalized)->firstOrFail();

        abort_unless($attempt->isFinished(), 404);
        abort_unless((int) $attachment->quiz_attempt_id === (int) $attempt->id, 404);

        return $request->boolean('apercu')
            ? self::previewAttachment($attachment)
            : self::downloadAttachment($attachment);
    }

    /**
     * Le flux de téléchargement, partagé par les trois portes d'accès
     * (étudiant, administration, correcteur) : chacune vérifie les droits à sa
     * façon, puis appelle ceci. Le fichier est cherché sur le disque privé, et
     * jamais dans un dossier public.
     */
    public static function downloadAttachment(QuizAttachment $attachment)
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($attachment->file_path), 404);

        return response()->download(
            $disk->path($attachment->file_path),
            basename($attachment->original_name ?: $attachment->stored_name)
        );
    }

    /**
     * Sert une pièce jointe en aperçu dans la page, quand c'est une image.
     *
     * Le fichier reste sur le disque privé : c'est le serveur qui le lit et le
     * renvoie au navigateur. Seules les images matricielles sont servies en
     * ligne — le type **réel** du fichier est revérifié, pas celui annoncé à
     * l'envoi — et tout le reste (un PDF, un format inattendu) retombe sur le
     * téléchargement, jamais sur un affichage dans la page.
     */
    public static function previewAttachment(QuizAttachment $attachment)
    {
        if (! $attachment->isImage()) {
            return self::downloadAttachment($attachment);
        }

        $disk = Storage::disk('local');

        abort_unless($disk->exists($attachment->file_path), 404);

        $mime = $disk->mimeType($attachment->file_path);

        if (! in_array($mime, QuizAttachment::IMAGE_MIMES, true)) {
            return self::downloadAttachment($attachment);
        }

        // Affiché dans la page (`inline`), avec le type réel et `nosniff` : le
        // navigateur ne devine rien et n'exécute rien. L'en-tête est posé
        // explicitement : sans lui, un navigateur pourrait proposer de
        // télécharger l'aperçu au lieu de le montrer.
        return response()
            ->file($disk->path($attachment->file_path), [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->setContentDisposition('inline');
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
            // plus à ce qui est enregistré. Son adresse, en revanche, doit
            // respecter la même règle que celle saisie à la main : une adresse,
            // une copie.
            if ($attempt->student_name !== null) {
                $this->assertEmailUnused($quiz, $attempt->student_email, $attempt->id);

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

            $email = $this->normalizedEmail($request);

            $this->assertEmailUnused($quiz, $email, $attempt->id);

            $attempt->update([
                'student_name' => trim((string) $request->input('student_name')),
                'student_email' => $email,
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
        $email = null;

        if (! $quiz->is_anonymous) {
            $request->validate([
                'student_name' => ['required', 'string', 'max:255'],
                'student_email' => ['nullable', 'email', 'max:255'],
                'student_major' => ['nullable', 'string', 'max:255'],
            ], [
                'student_name.required' => 'Indiquez votre nom complet.',
                'student_email.email' => 'L\'adresse email saisie n\'est pas valide.',
            ]);

            $email = $this->normalizedEmail($request);

            // Sans référence préparée, rien d'autre n'identifie le candidat :
            // c'est ici que l'adresse email fait office de clé — sans elle, la
            // même personne pourrait recommencer indéfiniment.
            $this->assertEmailUnused($quiz, $email);
        }

        return $quiz->attempts()->create([
            'reference' => QuizReference::generate(),
            // Évaluation anonyme : aucun nom n'est stocké, même si l'étudiant en
            // propose un. L'anonymat doit tenir côté base, pas côté affichage.
            'student_name' => $quiz->is_anonymous ? null : trim((string) $request->input('student_name')),
            'student_email' => $email,
            'student_major' => $quiz->is_anonymous || ! $request->filled('student_major')
                ? null
                : trim((string) $request->input('student_major')),
        ]);
    }

    /**
     * L'adresse email telle qu'elle sera enregistrée, ou null.
     */
    private function normalizedEmail(Request $request): ?string
    {
        return $request->filled('student_email')
            ? mb_strtolower(trim((string) $request->input('student_email')))
            : null;
    }

    /**
     * Une adresse email ne remet qu'une seule copie par évaluation.
     *
     * Le contrôle ne porte que sur les copies **engagées** (en cours, remises,
     * temps écoulé) : une ligne de liste importée n'est pas une soumission, et
     * une copie que son auteur a commencée mais pas rendue doit pouvoir être
     * reprise avec la même adresse.
     *
     * C'est une règle de contrôleur et non un index unique, exactement comme
     * pour les dépôts de travaux : la base contient déjà des histoires, et un
     * index unique échouerait à la migration là où une règle explique son refus.
     */
    private function assertEmailUnused(Form $quiz, ?string $email, ?int $ignoreAttemptId = null): void
    {
        if ($email === null || $email === '') {
            return;
        }

        $taken = $quiz->attempts()
            ->where('student_email', $email)
            ->whereIn('status', self::ENGAGED_STATUSES)
            ->when($ignoreAttemptId !== null, fn ($query) => $query->whereKeyNot($ignoreAttemptId))
            ->exists();

        if (! $taken) {
            return;
        }

        throw ValidationException::withMessages([
            'student_email' => 'Une copie a déjà été remise pour cette évaluation avec cette adresse email. Une seule copie par étudiant est acceptée : adressez-vous à votre enseignant si vous pensez qu\'il s\'agit d\'une erreur.',
        ]);
    }

    /**
     * L'empreinte de l'appareil : lue si elle existe, posée sinon.
     *
     * La valeur est un identifiant opaque ; elle ne dit rien de la machine, et
     * un navigateur qui l'efface (navigation privée, poste public) repart de
     * zéro. C'est assumé : cette empreinte complète la référence et l'adresse
     * email, elle ne les remplace pas.
     */
    private function deviceToken(): string
    {
        // Cookie chiffré par le framework : ce que reçoit le serveur a déjà été
        // déchiffré par le middleware, donc aucune vérification supplémentaire
        // n'est nécessaire — seulement un contrôle de forme, pour ne pas écrire
        // n'importe quoi dans la colonne depuis un cookie forgé à la main.
        $existing = request()->cookie(self::DEVICE_COOKIE);

        if (is_string($existing) && preg_match('/^[A-Za-z0-9]{20,64}$/', $existing) === 1) {
            return $existing;
        }

        $token = Str::random(40);

        Cookie::queue(self::DEVICE_COOKIE, $token, self::DEVICE_COOKIE_MINUTES);

        return $token;
    }

    /**
     * Cet appareil a-t-il déjà remis une copie de cette évaluation ?
     *
     * Uniquement si l'évaluation l'a demandé ({@see Form::quizBlocksSameDevice()}).
     */
    private function deviceAlreadySubmitted(Form $quiz, string $device): bool
    {
        if (! $quiz->quizBlocksSameDevice()) {
            return false;
        }

        return $quiz->attempts()
            ->where('device_token', $device)
            ->whereIn('status', [QuizAttempt::STATUS_SUBMITTED, QuizAttempt::STATUS_EXPIRED])
            ->exists();
    }

    /**
     * Fixe le chrono et ouvre la session du navigateur.
     */
    private function startTimer(Form $quiz, QuizAttempt $attempt, ?string $device = null)
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
                'device_token' => $device,
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

        // Échéance atteinte : on corrige ce qui a été répondu et on clôt. Le
        // chrono du candidat et l'heure de fermeture de l'épreuve y mènent tous
        // les deux, et closeAtDeadline() choisit la phrase qui convient.
        if ($attempt->hasExpired()) {
            return $this->closeAtDeadline($quiz, $attempt);
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

        // Le poste change de mains : ni la copie rendue, ni ce que l'attente
        // avait retenu ne doivent suivre le candidat suivant.
        session()->forget(['quiz_attempt.'.$quiz->id, $this->waitingKey($quiz)]);

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
     * Valide et normalise la réponse rédigée, ou rend null si elle est vide.
     *
     * Le texte n'est plus obligatoire : une pièce jointe peut tenir lieu de
     * réponse. On rend donc `null` quand rien n'est tapé, et c'est l'appelant qui
     * décide si l'absence de texte **et** de fichier est un refus.
     *
     * Un texte fait uniquement d'espaces vaut vide : la règle `required` de
     * Laravel accepte «   » comme une chaîne non vide, ce qui laisserait passer
     * une question ouverte « répondue » sans un mot.
     */
    private function validatedOpenAnswer(Request $request): ?string
    {
        $request->validate([
            'answer_text' => ['nullable', 'string', 'max:'.QuizQuestionData::MAX_STUDENT_ANSWER],
        ], [
            'answer_text.max' => 'Votre réponse ne peut pas dépasser '.QuizQuestionData::MAX_STUDENT_ANSWER.' caractères.',
        ]);

        $text = trim((string) $request->input('answer_text'));

        if ($text === '') {
            return null;
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
     * Valide les pièces jointes d'une réponse et rend les fichiers reçus.
     *
     * Image ou PDF, 1 Mo maximum, deux fichiers par question. La validation est
     * faite ici et non dans le formulaire : une requête peut toujours être forgée
     * à la main, et c'est le serveur qui doit refuser un fichier trop gros ou d'un
     * format inattendu.
     *
     * @return array<int, \Illuminate\Http\UploadedFile>
     */
    private function validatedAttachments(Request $request): array
    {
        $files = $request->file('attachments', []);
        $files = is_array($files) ? array_values(array_filter($files)) : ($files === null ? [] : [$files]);

        if ($files === []) {
            return [];
        }

        $request->validate([
            'attachments' => ['array', 'max:'.self::ATTACHMENT_MAX_PER_QUESTION],
            'attachments.*' => [
                'file',
                'max:'.self::ATTACHMENT_MAX_KB,
                'mimes:'.implode(',', self::ATTACHMENT_EXTENSIONS),
                'extensions:'.implode(',', self::ATTACHMENT_EXTENSIONS),
            ],
        ], [
            'attachments.max' => 'Vous pouvez joindre au maximum '.self::ATTACHMENT_MAX_PER_QUESTION.' documents par question.',
            'attachments.*.max' => 'Chaque document ne peut pas dépasser 1 Mo.',
            'attachments.*.mimes' => 'Le document doit être une image (jpg, png, webp, gif) ou un PDF.',
            'attachments.*.extensions' => 'Le document doit être une image (jpg, png, webp, gif) ou un PDF.',
        ]);

        return $files;
    }

    /**
     * Range les pièces jointes sur le disque privé et crée leurs lignes.
     *
     * Le nom stocké est un UUID : un nom choisi par l'utilisateur ne touche donc
     * jamais le système de fichiers, et deux dépôts du même « scan.pdf » ne se
     * recouvrent pas. Le nom d'origine, lui, est conservé pour l'affichage et le
     * téléchargement.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    private function storeAttachments(QuizAttempt $attempt, FormField $question, array $files): void
    {
        if ($files === []) {
            return;
        }

        $directory = 'quiz-attachments/'.$attempt->form_id.'/'.$attempt->id;

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $storedName = Str::uuid()->toString().'.'.$extension;
            $path = $file->storeAs($directory, $storedName, 'local');

            QuizAttachment::create([
                'quiz_attempt_id' => $attempt->id,
                'form_field_id' => $question->id,
                'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'stored_name' => $storedName,
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            ]);
        }
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

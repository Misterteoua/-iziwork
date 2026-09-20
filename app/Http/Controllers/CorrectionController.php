<?php

namespace App\Http\Controllers;

use App\Models\FormField;
use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizGradeReview;
use App\Support\QuizCopyGrading;
use App\Support\QuizReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'espace d'un correcteur externe.
 *
 * Rien de ce qui suit ne partage une clé de session avec l'administration : un
 * correcteur n'a aucun accès aux pages `/admin`, par construction et non par
 * prudence (voir App\Http\Middleware\GraderAuth).
 *
 * Ce qu'il peut faire est volontairement étroit : ouvrir ses copies assignées,
 * les noter, les commenter, et exporter ce qu'il a lui-même corrigé. Ni réglage,
 * ni lien d'étudiant, ni remise à zéro, ni export global : ce qui n'existe pas
 * dans son espace ne peut pas être fait par erreur.
 */
class CorrectionController extends Controller
{
    /** Essais de connexion tolérés par minute et par adresse : voir AppServiceProvider. */
    private const CSV_SEPARATOR = ';';

    public function __construct(
        private readonly QuizCopyGrading $copyGrading,
    ) {}

    /**
     * Sans session : où trouver son lien.
     *
     * Une page, et non une redirection vers l'écran d'administration : un
     * correcteur égaré n'a rien à faire sur le formulaire de connexion des
     * administrateurs, et son lien est la seule porte de son espace.
     */
    public function entry()
    {
        return view('correction.entry');
    }

    /**
     * La page d'accès, ouverte par le lien personnel (`/correction/{code}`).
     *
     * Le lien nomme la mission — un correcteur qui reçoit trois liens doit
     * savoir lequel il ouvre — mais ne donne accès à rien : l'email et la
     * référence restent nécessaires. C'est ce qui rend un lien transféré
     * inoffensif.
     */
    public function login(Request $request, string $code)
    {
        $grader = $this->graderForCode($code);

        if ($this->sessionGraderId($request) === $grader->getKey() && ! $grader->isExpired()) {
            return redirect()->route('correction.index');
        }

        return view('correction.login', [
            'grader' => $grader,
            'code' => $grader->link_code,
            'expired' => $grader->isExpired(),
            'quizzes' => $grader->forms()->orderBy('title')->get(),
        ]);
    }

    /**
     * Vérifie l'email et la référence, puis ouvre la session du correcteur.
     *
     * Le message d'erreur est unique, quelle que soit la cause : distinguer
     * « email inconnu » de « référence fausse » dirait à qui essaie lequel des
     * deux il a trouvé. Le frein de cinq tentatives par minute est posé sur la
     * route, comme pour la connexion d'administration.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'email' => ['required', 'string', 'max:190'],
            'reference' => ['required', 'string', 'max:32'],
        ]);

        $grader = Grader::where('link_code', $validated['code'])->first();
        $reference = QuizReference::normalize($validated['reference']);

        $matches = $grader !== null
            && $reference !== null
            && hash_equals($grader->reference, $reference)
            && mb_strtolower(trim($validated['email'])) === $grader->email;

        if (! $matches) {
            throw ValidationException::withMessages([
                'reference' => 'Email ou référence incorrecte.',
            ]);
        }

        if ($grader->isExpired()) {
            throw ValidationException::withMessages([
                'reference' => 'Votre délai de correction est dépassé : demandez la prolongation à l’organisation.',
            ]);
        }

        // Nouvelle session à la connexion : un identifiant de session obtenu
        // avant l'authentification ne doit pas devenir un accès.
        $request->session()->regenerate();
        $request->session()->put('grader', ['id' => $grader->getKey()]);

        $grader->touchActivity($request);

        return redirect()->route('correction.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('grader');

        return redirect()->route('correction.entry')
            ->with('success', 'Vous êtes déconnecté. Votre lien reste valable jusqu’à son échéance.');
    }

    /**
     * Le tableau de bord du correcteur : ses évaluations, sa file de copies, et
     * ce qu'il a déjà corrigé.
     */
    public function index(Request $request)
    {
        $grader = $this->currentGrader($request);
        $forms = $grader->forms()->orderBy('title')->get();

        $queue = QuizAttempt::query()
            ->whereIn('form_id', $forms->pluck('id'))
            ->awaitsManualGrading()
            ->with('form')
            ->get();

        // Les copies où il a déjà posé une note ou un commentaire — y compris
        // celles que l'administration a reprises depuis : son travail reste le
        // sien, et le voir signalé vaut mieux que le découvrir par hasard.
        $graded = $this->gradedAttempts($grader)
            ->with('form')
            ->withCount(['answers as revisions_count' => fn ($answers) => $answers
                ->whereHas('reviews', fn ($reviews) => $reviews->where('previous_grader_id', $grader->getKey()))])
            ->get();

        return view('correction.index', [
            'grader' => $grader,
            'quizzes' => $forms,
            'queue' => $queue,
            'graded' => $graded,
            'pendingByForm' => $queue->countBy('form_id'),
        ]);
    }

    /**
     * La copie à corriger, dans la même mise en page que celle de
     * l'administrateur : la carte d'une question est partagée, donc une note se
     * pose de la même façon des deux côtés.
     */
    public function show(Request $request, QuizAttempt $attempt)
    {
        $grader = $this->currentGrader($request);
        $this->assertInScope($grader, $attempt);

        if (! $attempt->isFinished()) {
            return redirect()->route('correction.index')
                ->with('error', 'Cette copie n’est pas encore rendue : il n’y a rien à corriger.');
        }

        $quiz = $attempt->form;

        $attempt->load([
            'answers.field',
            'answers.grader',
            'answers.gradingAdmin',
            // Les relectures de l'administration : une note reprise s'affiche en
            // lecture seule, avec son motif.
            'answers.reviews.previousGrader',
            'answers.reviews.previousAdmin',
        ]);

        $series = $request->boolean('serie');
        $queue = $this->queue($grader);

        $position = $queue->search($attempt->id);

        return view('correction.grade', [
            'grader' => $grader,
            'quiz' => $quiz,
            'attempt' => $attempt,
            'questions' => $attempt->questions(),
            'answers' => $attempt->answers->keyBy('form_field_id'),
            'pending' => $attempt->pendingManualCount(),
            'series' => $series,
            'position' => $position === false ? null : $position + 1,
            'queueSize' => $queue->count(),
            'next' => $series ? $this->nextToGrade($grader, $attempt->id) : null,
        ]);
    }

    public function store(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $grader = $this->currentGrader($request);
        $this->assertInScope($grader, $attempt);

        if (! $attempt->isFinished()) {
            return redirect()->route('correction.index')
                ->with('error', 'Cette copie n’est pas encore rendue : il n’y a rien à corriger.');
        }

        $pending = $this->copyGrading->save($request, $attempt, $grader);

        $saved = $pending === 0
            ? 'Correction enregistrée : la note de cette copie est définitive.'
            : 'Correction enregistrée : '.$pending.' réponse(s) encore en attente sur cette copie.';

        if (! $request->boolean('serie')) {
            return back()->with('success', $saved);
        }

        $next = $this->nextToGrade($grader, $attempt->getKey());

        if ($next !== null) {
            return redirect()
                ->route('correction.show', [$next, 'serie' => 1])
                ->with('success', $pending === 0 ? 'Copie corrigée. Copie suivante.' : 'Copie enregistrée partiellement. Copie suivante.');
        }

        return redirect()->route('correction.index')->with('success', $saved.' Aucune copie n’attend plus de ce côté.');
    }

    /**
     * L'export du correcteur : **ses** notes et **ses** commentaires, et rien
     * d'autre.
     *
     * Une ligne par réponse qu'il a lui-même corrigée ou commentée, dans ses
     * seules évaluations affectées. Aucune copie qu'il n'a pas touchée n'y
     * figure, et il ne peut donc pas lire le travail d'un autre correcteur par
     * ce chemin.
     *
     * Les notes reprises par l'administration **restent** dans son export :
     * c'est son travail, et le lui retirer silencieusement serait le pire des
     * services. La colonne « Révision » dit ce qui a été décidé à sa place, avec
     * le motif, et « Mes points » garde la note qu'il avait lui-même posée
     * tandis que « Points retenus » donne celle qui compte.
     *
     * Évaluation anonyme : le nom reste hors du fichier, exactement comme dans
     * les exports de l'administration.
     */
    public function export(Request $request): StreamedResponse
    {
        $grader = $this->currentGrader($request);
        $graderId = $grader->getKey();

        $answers = QuizAnswer::query()
            ->whereHas('attempt', fn ($attempt) => $attempt->whereIn('form_id', $grader->forms()->pluck('forms.id')))
            ->whereHas('field', fn ($field) => $field->where('field_type', FormField::OPEN_TYPE))
            ->where(fn ($query) => $query
                ->where('graded_by_grader_id', $graderId)
                ->orWhereHas('reviews', fn ($reviews) => $reviews->where('previous_grader_id', $graderId)))
            ->with(['attempt.form', 'field', 'reviews.previousGrader', 'reviews.previousAdmin'])
            ->get()
            // Tri total, fait ici et non en base : une seule note de tri, donc
            // aucun risque qu'un paquet en saute une. Le volume d'un correcteur
            // — ses propres réponses — se tient sans effort en mémoire.
            ->sortBy(fn (QuizAnswer $answer): array => [
                (string) $answer->attempt?->form?->title,
                (string) $answer->attempt?->reference,
                (int) $answer->field?->order,
                (int) $answer->id,
            ])
            ->values();

        $filename = 'mes-corrections_'.now()->format('Y-m-d_Hi').'.csv';

        return response()->streamDownload(function () use ($answers, $graderId): void {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel affiche « Ã‰valuation ».
            fwrite($handle, "\xEF\xBB\xBF");
            $this->writeCsvRow($handle, [
                'Évaluation', 'Référence', 'Nom', 'Remise', 'Question', 'Réponse',
                'Mes points', 'Mon commentaire', 'Points retenus', 'Barème', 'Révision',
            ]);

            // Les réponses sont déjà en mémoire (le tri se fait en PHP) : on
            // écrit directement, sans redécouper.
            foreach ($answers as $answer) {
                // La relecture qui a remplacé **sa** note : c'est celle-là qu'il
                // doit lire, même si l'administration a retouché la sienne
                // ensuite.
                $review = $answer->reviews->first(
                    fn (QuizGradeReview $candidate): bool => $candidate->previous_grader_id === $graderId
                );

                $form = $answer->attempt?->form;

                $this->writeCsvRow($handle, [
                    $form?->title,
                    $answer->attempt?->reference,
                    $form?->is_anonymous ? null : $answer->attempt?->student_name,
                    $answer->attempt?->submitted_at?->format('d/m/Y H:i'),
                    $answer->field?->field_label,
                    $answer->answer_text,
                    $review?->previous_points ?? $answer->points_awarded,
                    $review ? $review->previous_comment : $answer->grader_comment,
                    $answer->points_awarded,
                    $answer->field?->points,
                    $this->reviewLabel($review),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Ce que la relecture a décidé, en une phrase lisible dans un tableur.
     *
     * Le motif y figure : un correcteur repris sans explication apprendrait
     * seulement qu'on l'a contredit.
     */
    private function reviewLabel(?QuizGradeReview $review): ?string
    {
        if ($review === null) {
            return null;
        }

        $when = $review->created_at?->format('d/m/Y H:i') ?? '';

        if ($review->reviewed_by_admin_id === null) {
            return 'Note modifiée par son auteur le '.$when;
        }

        return 'Reprise par l\'administration le '.$when
            .($review->reason ? ' — motif : '.$review->reason : '');
    }

    // ---------------------------------------------------------------- Interne

    /**
     * Une ligne CSV, avec la protection contre les formules : un texte d'étudiant
     * commençant par « = » ne doit pas être exécuté par le tableur qui ouvre le
     * fichier.
     *
     * @param  resource  $handle
     * @param  array<int, mixed>  $values
     */
    private function writeCsvRow($handle, array $values): void
    {
        $row = array_map(static function ($value): string {
            $value = (string) ($value ?? '');

            return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
        }, $values);

        fputcsv($handle, $row, self::CSV_SEPARATOR, '"', '');
    }

    private function graderForCode(string $code): Grader
    {
        $grader = Grader::where('link_code', $code)->first();

        // Un code inconnu est un code inconnu : rien à apprendre de la réponse.
        abort_if($grader === null, 404);

        return $grader;
    }

    private function currentGrader(Request $request): Grader
    {
        $grader = $request->attributes->get('grader');

        abort_unless($grader instanceof Grader, 403);

        return $grader;
    }

    /**
     * Le correcteur n'ouvre que les copies des évaluations qui lui sont
     * affectées. Un identifiant deviné ne mène à rien : c'est ici que le
     * périmètre est décidé, et il est décidé à chaque requête.
     */
    private function assertInScope(Grader $grader, QuizAttempt $attempt): void
    {
        abort_unless(
            $grader->forms()->whereKey($attempt->form_id)->exists(),
            404
        );
    }

    /**
     * Sa file : les copies en attente de ses évaluations, dans l'ordre de remise.
     *
     * @return Collection<int, int>
     */
    private function queue(Grader $grader): Collection
    {
        return QuizAttempt::query()
            ->whereIn('form_id', $grader->forms()->pluck('forms.id'))
            ->awaitsManualGrading()
            ->pluck('id');
    }

    private function nextToGrade(Grader $grader, ?int $exceptId): ?QuizAttempt
    {
        // La copie qui vient d'être enregistrée est exclue : une question
        // volontairement laissée en attente ferait sinon tourner la série en
        // rond sur elle-même.
        return QuizAttempt::query()
            ->whereIn('form_id', $grader->forms()->pluck('forms.id'))
            ->awaitsManualGrading()
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    /**
     * Les copies où ce correcteur a laissé une trace, dans l'ordre de remise.
     *
     * Une copie dont la note a été reprise par l'administration reste dans la
     * liste : sans cela, son travail semblerait s'être volatilisé.
     *
     * @return \Illuminate\Database\Eloquent\Builder<QuizAttempt>
     */
    private function gradedAttempts(Grader $grader)
    {
        return QuizAttempt::query()
            ->whereIn('form_id', $grader->forms()->pluck('forms.id'))
            ->whereHas('answers', fn ($answers) => $answers
                ->where('graded_by_grader_id', $grader->getKey())
                ->orWhereHas('reviews', fn ($reviews) => $reviews->where('previous_grader_id', $grader->getKey())))
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    private function sessionGraderId(Request $request): ?int
    {
        $session = $request->session()->get('grader');
        $id = is_array($session) ? ($session['id'] ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }
}

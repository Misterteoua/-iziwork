<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Grader;
use App\Models\QuizAttempt;
use App\Support\Qr\QrPng;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Les correcteurs externes, vus par l'administrateur.
 *
 * Trois choses seulement, et chacune répond à une question pratique :
 *   - qui corrige cette évaluation, et jusqu'à quand ;
 *   - quel lien lui transmettre (et son QR code, pour une remise en main
 *     propre) ;
 *   - où en est son travail.
 *
 * L'échéance n'est jamais appliquée par une tâche planifiée : elle est relue à
 * chaque requête du correcteur (voir Grader::isExpired()). Il n'y a donc rien à
 * faire tourner sur le serveur, et une prolongation prend effet immédiatement.
 *
 * Le lien personnel est **stable** : le même à chaque affichage de la page. Un
 * correcteur qui note son adresse une fois n'a pas à la redemander. Le bouton de
 * régénération existe pour le cas inverse — un lien qu'on juge compromis — et il
 * invalide l'ancien aussitôt.
 */
class GraderController extends Controller
{
    /** Délai proposé par défaut, en jours. */
    private const DEFAULT_DAYS = 7;

    /** Bornes du délai : une mission d'un an n'est pas une mission. */
    private const MIN_DAYS = 1;

    private const MAX_DAYS = 365;

    public function index(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $graders = $quiz->graders()->orderBy('name')->get();

        return view('admin.quizzes.graders', [
            'quiz' => $quiz,
            'graders' => $graders,
            'defaultDays' => self::DEFAULT_DAYS,
            'minDays' => self::MIN_DAYS,
            'maxDays' => self::MAX_DAYS,
            // Copies de cette évaluation qui attendent encore une rédaction
            // notée : c'est le travail que les correcteurs se partagent.
            'pending' => $quiz->attempts()->awaitsManualGrading()->count(),
            'progress' => $this->progress($quiz, $graders),
            // Le QR est calculé à partir du lien, jamais enregistré : il suit
            // donc automatiquement une régénération de ce lien.
            'qrCodes' => $graders->mapWithKeys(
                fn (Grader $grader): array => [$grader->getKey() => QrPng::dataUri($grader->link())]
            ),
        ]);
    }

    /**
     * Crée un correcteur et l'affecte à cette évaluation.
     *
     * Un email déjà connu n'entraîne pas un second compte : le lien personnel
     * est unique par personne, et deux liens pour le même correcteur
     * produiraient deux adresses à retenir et deux références à transmettre.
     * L'existant est réutilisé, ce que le message annonce.
     */
    public function store(Request $request, Form $quiz): RedirectResponse
    {
        $this->assertQuiz($quiz);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'days' => ['required', 'integer', 'min:'.self::MIN_DAYS, 'max:'.self::MAX_DAYS],
        ], [
            'name.required' => 'Le nom du correcteur est obligatoire.',
            'email.required' => 'L’adresse email est obligatoire : c’est elle qui accompagne la référence.',
            'email.email' => 'Cette adresse email n’est pas valide.',
            'days.required' => 'Le délai de correction est obligatoire.',
            'days.min' => 'Le délai doit être d’au moins '.self::MIN_DAYS.' jour.',
            'days.max' => 'Le délai ne peut pas dépasser '.self::MAX_DAYS.' jours.',
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $adminId = (int) $request->session()->get('admin_user.id');

        $existing = Grader::where('email', $email)->first();

        // Fin de journée : un délai de trois jours laisse travailler jusqu'au
        // soir du troisième, ce qu'attend celui qui l'a demandé.
        $expiresAt = Carbon::now()->addDays((int) $validated['days'])->endOfDay();

        $grader = $existing ?? Grader::createFor($validated['name'], $email, $expiresAt, $adminId ?: null);

        if ($existing !== null) {
            // Le délai est celui qu'on vient de choisir, et le nom suit la
            // dernière saisie : la fiche doit dire la vérité de maintenant.
            $existing->update(['name' => trim($validated['name']), 'expires_at' => $expiresAt]);
        }

        $alreadyAssigned = $grader->forms()->whereKey($quiz->getKey())->exists();

        if (! $alreadyAssigned) {
            $grader->forms()->attach($quiz->getKey(), ['created_by' => $adminId ?: null]);
        }

        return redirect()->route('admin.quizzes.graders', $quiz)->with('success', $this->welcomeMessage($existing !== null, $alreadyAssigned));
    }

    /**
     * Un nouveau code de lien : l'ancien cesse aussitôt de fonctionner.
     *
     * Le QR code suit tout seul : il est calculé à partir du lien à chaque
     * affichage, jamais enregistré. Un QR périmé est donc impossible.
     */
    public function regenerateLink(Form $quiz, Grader $grader): RedirectResponse
    {
        $this->assertGrader($quiz, $grader);

        $grader->rotateLink();

        return redirect()->route('admin.quizzes.graders', $quiz)
            ->with('success', 'Nouveau lien généré : l’ancien ne fonctionne plus. Le QR code affiché est à jour.');
    }

    /**
     * Prolonge la mission, sans jamais la raccourcir.
     *
     * Un correcteur à qui l'on donne trois jours de plus ne doit pas se
     * retrouver avec moins de temps qu'avant parce qu'il restait un jour.
     */
    public function extend(Request $request, Form $quiz, Grader $grader): RedirectResponse
    {
        $this->assertGrader($quiz, $grader);

        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:'.self::MIN_DAYS, 'max:'.self::MAX_DAYS],
        ], [
            'days.min' => 'La prolongation doit être d’au moins '.self::MIN_DAYS.' jour.',
            'days.max' => 'La prolongation ne peut pas dépasser '.self::MAX_DAYS.' jours.',
        ]);

        $from = $grader->expires_at !== null && $grader->expires_at->isFuture()
            ? $grader->expires_at
            : Carbon::now();

        $grader->update(['expires_at' => $from->copy()->addDays((int) $validated['days'])->endOfDay()]);

        return redirect()->route('admin.quizzes.graders', $quiz)->with(
            'success',
            'Délai prolongé jusqu’au '.$grader->expires_at->format('d/m/Y à H:i').' : l’accès est rouvert.'
        );
    }

    /**
     * Ferme immédiatement l'accès, sans effacer la personne.
     *
     * Le correcteur n'est jamais supprimé : ses notes doivent garder leur auteur,
     * et c'est ce qui permet de dire qui a corrigé quoi après une contestation.
     * Suspendre suffit — l'échéance passée, l'accès se ferme tout seul.
     */
    public function suspend(Form $quiz, Grader $grader): RedirectResponse
    {
        $this->assertGrader($quiz, $grader);

        $grader->update(['expires_at' => Carbon::now()]);

        return redirect()->route('admin.quizzes.graders', $quiz)
            ->with('success', 'Accès suspendu. Les notes déjà posées restent signées de son nom ; une prolongation rouvre l’accès.');
    }

    /**
     * Retire cette évaluation du périmètre du correcteur.
     *
     * La ligne d'affectation disparaît, le correcteur reste : s'il corrige une
     * autre évaluation, son lien et sa référence continuent de fonctionner.
     */
    public function detach(Form $quiz, Grader $grader): RedirectResponse
    {
        $this->assertGrader($quiz, $grader);

        $grader->forms()->detach($quiz->getKey());

        return redirect()->route('admin.quizzes.graders', $quiz)->with(
            'success',
            $grader->forms()->exists()
                ? 'Évaluation retirée de son périmètre : il garde ses autres évaluations.'
                : 'Évaluation retirée : ce correcteur n’a plus aucune copie à corriger.'
        );
    }

    /**
     * La fiche de mission, à imprimer et à remettre en main propre.
     *
     * Le QR code est calculé côté serveur, en haute résolution : il s'imprime
     * proprement et reste lisible après photocopie. La référence n'y figure que
     * si on le demande (`?reference=1`), parce qu'une fiche portant à la fois le
     * lien et la clé devient un document qui ouvre tout à lui seul — à réserver
     * à une remise en main propre.
     */
    public function mission(Request $request, Form $quiz, Grader $grader)
    {
        $this->assertGrader($quiz, $grader);

        $withReference = $request->boolean('reference');

        $pdf = Pdf::loadView('admin.quizzes.grader-mission-pdf', [
            'quiz' => $quiz,
            'grader' => $grader,
            'link' => $grader->link(),
            // Échelle 10 : environ 290 px pour un QR de version 3, soit environ
            // 25 mm sur le papier — largement au-delà de ce qu'un téléphone
            // demande, et sans dépendre de l'écran.
            'qr' => QrPng::dataUri($grader->link(), 10),
            'withReference' => $withReference,
        ]);

        return $pdf->download('fiche-correction_'.str($grader->name)->slug().'.pdf');
    }

    // ---------------------------------------------------------------- Interne

    /**
     * Avancement de chaque correcteur sur cette évaluation : combien de copies
     * portent une trace de lui, et combien attendent encore.
     *
     * Une requête par correcteur, et non par copie : une évaluation n'a qu'une
     * poignée de correcteurs, mais peut avoir des centaines de copies.
     *
     * @param  \Illuminate\Support\Collection<int, Grader>  $graders
     * @return array<int, int>
     */
    private function progress(Form $quiz, $graders): array
    {
        $progress = [];

        foreach ($graders as $grader) {
            $progress[$grader->getKey()] = QuizAttempt::query()
                ->where('form_id', $quiz->getKey())
                ->whereHas('answers', fn ($answers) => $answers->where('graded_by_grader_id', $grader->getKey()))
                ->count();
        }

        return $progress;
    }

    private function welcomeMessage(bool $existing, bool $alreadyAssigned): string
    {
        if ($existing && $alreadyAssigned) {
            return 'Ce correcteur est déjà affecté à cette évaluation : son délai a été mis à jour.';
        }

        if ($existing) {
            return 'Correcteur déjà connu : sa référence et son lien ont été conservés, l’évaluation lui a été affectée.';
        }

        return 'Correcteur créé. Transmettez-lui son lien, et sa référence par un autre canal que le lien.';
    }

    private function assertQuiz(Form $quiz): void
    {
        abort_unless($quiz->isQuiz(), 404);
    }

    /**
     * La fiche d'un correcteur ne s'ouvre que depuis une évaluation qui lui est
     * affectée : sinon, la même URL servirait à lire le lien d'un correcteur
     * d'une autre évaluation.
     */
    private function assertGrader(Form $quiz, Grader $grader): void
    {
        $this->assertQuiz($quiz);

        abort_unless($grader->forms()->whereKey($quiz->getKey())->exists(), 404);
    }
}

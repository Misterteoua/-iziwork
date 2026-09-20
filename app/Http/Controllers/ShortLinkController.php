<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Résolution des liens courts : /l/{code}.
 *
 * Un lien court ne fait que raccourcir un lien long qui existait déjà, et
 * n'ouvre aucune porte nouvelle :
 *   - `quiz` et `form` renvoient vers le lien long, qui continue de fonctionner
 *     exactement comme avant ;
 *   - `result` affiche le résultat d'une copie **sans toucher à la session**,
 *     pour que l'étudiant retrouve son résultat depuis n'importe quel appareil
 *     — et pour qu'un lien reçu par message ne perturbe jamais une épreuve en
 *     cours sur un poste partagé.
 *
 * Ce qui protège les liens de résultat, c'est le code lui-même : huit
 * caractères tirés au sort, jamais un identifiant qui s'incrémente.
 */
class ShortLinkController extends Controller
{
    /**
     * Essayages infructueux tolérés par minute et par adresse IP.
     *
     * Seuls les codes **inconnus** sont comptés : une salle informatique entière
     * derrière une même connexion ouvre ses liens sans jamais être freinée,
     * alors qu'un balayage de codes se heurte très vite à un mur.
     */
    private const MISSES_PER_MINUTE = 30;

    public function __invoke(Request $request, string $code)
    {
        $link = ShortLink::query()->with('form')->where('code', $code)->first();

        if ($link === null) {
            $this->countMiss($request);

            abort(404);
        }

        $form = $link->form;

        abort_if($form === null, 404);

        if ($link->kind === ShortLink::KIND_QUIZ) {
            abort_unless($form->isQuiz(), 404);

            return $this->temporary(route('quiz.start', $form->token));
        }

        if ($link->kind === ShortLink::KIND_FORM) {
            abort_if($form->isQuiz(), 404);

            return $this->temporary(route('submit.form', $form->token));
        }

        if ($link->kind === ShortLink::KIND_RESULT) {
            return $this->showResult($form, $link);
        }

        abort(404);
    }

    /**
     * Le résultat d'une copie, lu par son lien personnel.
     *
     * Le rendu est celui de la page de résultat habituelle : une seule source de
     * vérité, donc un lien de suivi qui montre toujours exactement ce que montre
     * la page.
     */
    private function showResult(Form $quiz, ShortLink $link)
    {
        $attempt = $link->attempt;

        abort_if($attempt === null || ! $quiz->isQuiz(), 404);

        // Une copie encore en cours ne se montre pas : l'étudiant est renvoyé
        // vers la page d'accès, comme le fait la page de résultat elle-même.
        if (! $attempt->isFinished()) {
            return $this->temporary(route('quiz.start', $quiz->token));
        }

        $view = app(QuizAttemptController::class)->renderResult($quiz, $attempt, true);

        return response($view)->withHeaders([
            // Un lien personnel a sa place dans une conversation, pas dans un
            // moteur de recherche.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Redirection temporaire (302), jamais permanente : un lien court doit
     * pouvoir être régénéré, et un 301 resterait gravé dans le navigateur de
     * l'étudiant.
     */
    private function temporary(string $url): RedirectResponse
    {
        return redirect()->to($url, 302)->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function countMiss(Request $request): void
    {
        $key = 'short-link-misses|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MISSES_PER_MINUTE)) {
            abort(429);
        }

        RateLimiter::hit($key, 60);
    }
}

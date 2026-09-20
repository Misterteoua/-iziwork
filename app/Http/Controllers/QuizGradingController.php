<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\QuizAttempt;
use App\Support\QuizCopyGrading;
use App\Support\QuizGrader;
use Illuminate\Http\Request;

/**
 * Correction manuelle d'une copie : les réponses rédigées.
 *
 * Une machine ne note pas un texte libre. Ce contrôleur existe donc pour la
 * seule partie du barème que l'enseignant doit trancher lui-même : il lit la
 * réponse, la compare à son propre guide, et attribue des points — entiers ou
 * partiels. Les questions à propositions, elles, sont déjà corrigées et ne sont
 * affichées que pour donner la copie complète sous les yeux.
 *
 * Trois garanties tenues ici :
 *   1. on ne note qu'une copie rendue — une épreuve en cours n'a pas de note ;
 *   2. on ne note que les réponses de cette copie — un identifiant glissé à la
 *      main ne permet pas d'aller noter la copie d'un autre candidat ;
 *   3. la note ne peut pas dépasser le barème de la question.
 *
 * Le mode « en série » (`?serie=1`) enchaîne les copies les unes après les
 * autres : après l'enregistrement, on passe directement à la copie suivante à
 * corriger, sans repasser par la liste des résultats. Le déroulé reste le même
 * code : la série n'est qu'une façon de choisir la copie suivante.
 */
class QuizGradingController extends Controller
{
    public function __construct(
        private readonly QuizGrader $grader,
        private readonly QuizCopyGrading $copyGrading,
    ) {}

    /**
     * Entrée de la correction en série : ouvre la première copie à corriger.
     */
    public function series(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $next = $this->nextToGrade($quiz);

        if ($next === null) {
            return redirect()->route('admin.quizzes.results', $quiz)
                ->with('success', $this->nothingLeftMessage());
        }

        return redirect()->route('admin.quizzes.attempts.grade', [$quiz, $next, 'serie' => 1]);
    }

    public function show(Request $request, Form $quiz, QuizAttempt $attempt)
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        if (! $attempt->isFinished()) {
            return redirect()->route('admin.quizzes.results', $quiz)
                ->with('error', 'Cette copie n\'est pas encore rendue : il n\'y a rien à corriger.');
        }

        // `grader`, `gradingAdmin` et les relectures sont chargés d'avance : la
        // page affiche qui a posé chaque note et ce que la relecture a changé,
        // et sans cela une copie de cinquante questions déclencherait autant de
        // requêtes.
        $attempt->load([
            'answers.field',
            'answers.grader',
            'answers.gradingAdmin',
            'answers.reviews.previousGrader',
            'answers.reviews.previousAdmin',
        ]);

        $series = $request->boolean('serie');
        $queue = $this->queue($quiz);
        $position = $queue->search($attempt->id);

        return view('admin.quizzes.grade', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'questions' => $attempt->questions(),
            'answers' => $attempt->answers->keyBy('form_field_id'),
            'pending' => $attempt->pendingManualCount(),
            // Série : où l'on en est, et quelle copie vient ensuite. Une copie déjà
            // entièrement corrigée n'est plus dans la file : c'est ce qui évite
            // qu'un « suivant » renvoie sur elle.
            'series' => $series,
            'position' => $position === false ? null : $position + 1,
            'queueSize' => $queue->count(),
            'next' => $series ? $this->nextToGrade($quiz, $attempt->id) : null,
        ]);
    }

    public function store(Request $request, Form $quiz, QuizAttempt $attempt)
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        if (! $attempt->isFinished()) {
            return redirect()->route('admin.quizzes.results', $quiz)
                ->with('error', 'Cette copie n\'est pas encore rendue : il n\'y a rien à corriger.');
        }

        // Même enregistrement que celui du correcteur externe : une seule règle
        // de notation, donc une note qui ne dépend pas de qui a corrigé. La
        // trace garde en revanche l'auteur, qui est ici l'administrateur.
        $pending = $this->copyGrading->save(
            $request,
            $attempt,
            (int) $request->session()->get('admin_user.id'),
        );

        $saved = $pending === 0
            ? 'Correction enregistrée : la note de cette copie est définitive.'
            : 'Correction enregistrée : '.$pending.' réponse(s) encore en attente sur cette copie.';

        if (! $request->boolean('serie')) {
            return back()->with('success', $saved);
        }

        // En série : on enchaîne sur la copie suivante. La copie courante est
        // exclue de la recherche, sinon une question volontairement laissée en
        // attente ramènerait sans fin sur la même copie.
        $next = $this->nextToGrade($quiz, $attempt->id);

        if ($next !== null) {
            return redirect()
                ->route('admin.quizzes.attempts.grade', [$quiz, $next, 'serie' => 1])
                ->with('success', $pending === 0 ? 'Copie corrigée. Copie suivante.' : 'Copie enregistrée partiellement. Copie suivante.');
        }

        return redirect()->route('admin.quizzes.results', $quiz)->with(
            'success',
            $pending === 0
                ? $saved.' '.$this->nothingLeftMessage()
                : $saved.' Il ne reste qu\'elle à corriger.'
        );
    }

    /**
     * Prochaine copie à corriger.
     *
     * @param  ?int  $exceptId  copie à ne pas reproposer (celle qui vient d'être corrigée)
     */
    private function nextToGrade(Form $quiz, ?int $exceptId = null): ?QuizAttempt
    {
        return $quiz->attempts()
            ->awaitsManualGrading()
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    /**
     * File des copies à corriger, dans l'ordre de correction.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function queue(Form $quiz): \Illuminate\Support\Collection
    {
        return $quiz->attempts()->awaitsManualGrading()->pluck('id');
    }

    private function nothingLeftMessage(): string
    {
        return 'Aucune copie n\'attend plus : toutes les réponses rédigées de cette évaluation sont corrigées.';
    }

    private function assertQuiz(Form $quiz): void
    {
        abort_unless($quiz->isQuiz(), 404);
    }

    private function assertAttempt(Form $quiz, QuizAttempt $attempt): void
    {
        abort_unless((int) $attempt->form_id === (int) $quiz->id, 404);
    }
}

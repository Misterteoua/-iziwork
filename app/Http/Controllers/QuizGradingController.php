<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Support\QuizGrader;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
 */
class QuizGradingController extends Controller
{
    public function __construct(private readonly QuizGrader $grader) {}

    public function show(Form $quiz, QuizAttempt $attempt)
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        if (! $attempt->isFinished()) {
            return redirect()->route('admin.quizzes.results', $quiz)
                ->with('error', 'Cette copie n\'est pas encore rendue : il n\'y a rien à corriger.');
        }

        $attempt->load('answers.field');

        return view('admin.quizzes.grade', [
            'quiz' => $quiz,
            'attempt' => $attempt,
            'questions' => $attempt->questions(),
            'answers' => $attempt->answers->keyBy('form_field_id'),
            'pending' => $attempt->pendingManualCount(),
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

        $attempt->load('answers.field');

        $openAnswers = $attempt->answers->filter(
            static fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true
        );

        $validated = $request->validate([
            'points' => ['required', 'array'],
            'points.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'points.required' => 'Le formulaire de correction est incomplet.',
            'points.*.numeric' => 'Une note doit être un nombre.',
            'points.*.min' => 'Une note ne peut pas être négative.',
        ]);

        foreach ($openAnswers as $answer) {
            $raw = $validated['points'][$answer->id] ?? null;

            // Champ laissé vide : la réponse reste « en attente ». C'est ce qui
            // permet de corriger une copie en plusieurs fois sans qu'une note
            // provisoire soit prise pour une note finale.
            if ($raw === null || $raw === '') {
                continue;
            }

            $max = (float) $answer->field->points;

            if ((float) $raw > $max) {
                throw ValidationException::withMessages([
                    'points.'.$answer->id => 'La note ne peut pas dépasser le barème de la question ('.$max.' point(s)).',
                ]);
            }

            $this->grader->award($answer, (float) $raw);
        }

        $this->grader->recompute($attempt);

        $pending = $attempt->pendingManualCount();

        return back()->with(
            'success',
            $pending === 0
                ? 'Correction enregistrée : la note de cette copie est définitive.'
                : 'Correction enregistrée : '.$pending.' réponse(s) encore en attente.'
        );
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

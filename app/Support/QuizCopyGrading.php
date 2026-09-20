<?php

namespace App\Support;

use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * L'enregistrement d'une correction de copie, écrit une seule fois.
 *
 * L'administrateur et le correcteur externe corrigent exactement la même chose
 * au même endroit : seuls l'auteur de la note et le périmètre d'accès changent.
 * Dupliquer cette logique aurait produit deux règles qui divergent à la première
 * évolution — et la note d'un étudiant ne peut pas dépendre de qui a cliqué.
 *
 * Trois garanties, tenues ici et donc valables pour les deux :
 *   1. on ne touche qu'aux questions à réponse rédigée ;
 *   2. la note ne peut pas dépasser le barème de la question ;
 *   3. un champ laissé vide n'écrit rien — la réponse reste « en attente », ce
 *      qui permet de corriger une copie en plusieurs fois.
 */
final class QuizCopyGrading
{
    public function __construct(private readonly QuizGrader $grader) {}

    /**
     * Enregistre les notes et les commentaires d'une copie, et rend le nombre de
     * réponses rédigées encore en attente.
     *
     * @param  Grader|int|null  $by  l'auteur de la note (correcteur, ou identifiant d'administrateur)
     */
    public function save(Request $request, QuizAttempt $attempt, Grader|int|null $by): int
    {
        $attempt->load('answers.field');

        $openAnswers = $attempt->answers->filter(
            static fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true
        );

        $validated = $request->validate([
            'points' => ['required', 'array'],
            'points.*' => ['nullable', 'numeric', 'min:0'],
            'comments' => ['nullable', 'array'],
            'comments.*' => ['nullable', 'string', 'max:2000'],
        ], [
            'points.required' => 'Le formulaire de correction est incomplet.',
            'points.*.numeric' => 'Une note doit être un nombre.',
            'points.*.min' => 'Une note ne peut pas être négative.',
            'comments.*.max' => 'Un commentaire ne peut pas dépasser 2000 caractères.',
        ]);

        foreach ($openAnswers as $answer) {
            $raw = $validated['points'][$answer->id] ?? null;
            $comment = $validated['comments'][$answer->id] ?? null;

            // Champ vide : la réponse reste en attente. Un commentaire écrit
            // sans note n'est pas perdu pour autant — le perdre silencieusement
            // serait le pire des comportements pour qui vient de le rédiger.
            if ($raw === null || $raw === '') {
                if (trim((string) $comment) !== '') {
                    $this->grader->comment($answer, $comment, $by);
                }

                continue;
            }

            $max = (float) $answer->field->points;

            if ((float) $raw > $max) {
                throw ValidationException::withMessages([
                    'points.'.$answer->id => 'La note ne peut pas dépasser le barème de la question ('.$max.' point(s)).',
                ]);
            }

            $this->grader->award($answer, (float) $raw, $comment, $by);
        }

        $this->grader->recompute($attempt);

        return $attempt->pendingManualCount();
    }
}

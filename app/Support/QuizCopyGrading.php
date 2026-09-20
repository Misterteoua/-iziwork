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
 * au même endroit : seuls l'auteur de la note, le périmètre d'accès et le droit
 * de reprise changent. Dupliquer cette logique aurait produit deux règles qui
 * divergent à la première évolution — et la note d'un étudiant ne peut pas
 * dépendre de qui a cliqué.
 *
 * Quatre garanties, tenues ici et donc valables pour les deux :
 *   1. on ne touche qu'aux questions à réponse rédigée ;
 *   2. la note ne peut pas dépasser le barème de la question ;
 *   3. un champ laissé vide n'écrit rien — la réponse reste « en attente », ce
 *      qui permet de corriger une copie en plusieurs fois ;
 *   4. une note relue par l'administration est **définitive** : le correcteur ne
 *      peut plus la modifier, et l'administrateur qui reprend la note d'un
 *      correcteur doit dire **pourquoi**.
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
        // Les relectures sont chargées d'avance : la carte d'une question dit
        // qui a relu quoi, et une copie de cinquante questions ne doit pas
        // déclencher cinquante requêtes pour l'afficher.
        $attempt->load([
            'answers.field',
            'answers.reviews.previousGrader',
            'answers.reviews.previousAdmin',
            'answers.reviews.reviewer',
        ]);

        $openAnswers = $attempt->answers->filter(
            static fn (QuizAnswer $answer): bool => $answer->field?->isOpen() === true
        );

        $validated = $request->validate([
            'points' => ['required', 'array'],
            'points.*' => ['nullable', 'numeric', 'min:0'],
            'comments' => ['nullable', 'array'],
            'comments.*' => ['nullable', 'string', 'max:2000'],
            'review_reason' => ['nullable', 'array'],
            'review_reason.*' => ['nullable', 'string', 'max:500'],
        ], [
            'points.required' => 'Le formulaire de correction est incomplet.',
            'points.*.numeric' => 'Une note doit être un nombre.',
            'points.*.min' => 'Une note ne peut pas être négative.',
            'comments.*.max' => 'Un commentaire ne peut pas dépasser 2000 caractères.',
            'review_reason.*.max' => 'Un motif de reprise ne peut pas dépasser 500 caractères.',
        ]);

        // Un identifiant d'administrateur, et non un correcteur : c'est lui qui
        // signe la relecture, et c'est cette signature qui rend une note
        // définitive pour le correcteur.
        $reviewer = is_int($by) ? $by : null;

        foreach ($openAnswers as $answer) {
            if ($by instanceof Grader && $answer->reviewedByAdmin()) {
                // Note déjà relue : le correcteur ne la reprend pas, même s'il
                // rejoue le formulaire à la main.
                continue;
            }

            $raw = $validated['points'][$answer->id] ?? null;
            $comment = $validated['comments'][$answer->id] ?? null;
            $reason = trim((string) ($validated['review_reason'][$answer->id] ?? ''));

            $points = ($raw === null || $raw === '') ? null : (float) $raw;

            if ($points !== null) {
                $max = (float) $answer->field->points;

                if ($points > $max) {
                    throw ValidationException::withMessages([
                        'points.'.$answer->id => 'La note ne peut pas dépasser le barème de la question ('.$max.' point(s)).',
                    ]);
                }
            }

            // Reprendre la note d'un correcteur sans dire pourquoi laisserait
            // une trace muette : celui qui la découvrirait n'apprendrait rien.
            if ($reviewer !== null
                && $answer->heldByGrader()
                && $this->changes($answer, $points, $comment)
                && $reason === '') {
                throw ValidationException::withMessages([
                    'review_reason.'.$answer->id => 'Un motif est nécessaire pour reprendre une note posée par un correcteur.',
                ]);
            }

            // Rien de soumis sur cette réponse : elle reste en attente, et aucun
            // commentaire écrit au passage n'est perdu (voir record()).
            if ($points === null && $comment === null && $reason === '') {
                continue;
            }

            $this->grader->record($answer, $points, $comment, $by, $reason, $reviewer);
        }

        $this->grader->recompute($attempt);

        return $attempt->pendingManualCount();
    }

    /**
     * La décision soumise diffère-t-elle de ce qui est enregistré ?
     *
     * On compare la valeur réellement retenue (commentaire normalisé, note
     * arrondie au centième) : sinon un espace ajouté à la fin d'un commentaire
     * suffirait à réclamer un motif.
     */
    private function changes(QuizAnswer $answer, ?float $points, ?string $comment): bool
    {
        return ($points !== null && (float) $answer->points_awarded !== $points)
            || $answer->grader_comment !== QuizGrader::normalizeComment($comment);
    }
}

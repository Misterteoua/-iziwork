<?php

namespace App\Support;

use App\Models\FormField;
use App\Models\QuizAttempt;
use Illuminate\Support\Carbon;

/**
 * Correction automatique d'une évaluation.
 *
 * Le calcul vit ici, et non dans un contrôleur, pour deux raisons : il est
 * testable seul, et surtout il n'existe qu'un seul endroit qui décide d'une
 * note — la page de résultat, le PDF et la liste de l'administrateur lisent
 * tous la même valeur stockée, ils ne la recalculent jamais.
 */
final class QuizGrader
{
    /**
     * Corrige la participation et fige le résultat.
     *
     * Idempotent : une épreuve déjà corrigée n'est pas recorrigée, sinon un
     * double envoi (dernière question puis expiration du chrono, cas fréquent)
     * pourrait écraser une note par une autre.
     */
    public function finalize(QuizAttempt $attempt, bool $expired = false): QuizAttempt
    {
        if ($attempt->isFinished()) {
            return $attempt;
        }

        $questions = $attempt->questions();
        $answers = $attempt->answers()->get()->keyBy('form_field_id');

        $score = 0.0;

        foreach ($questions as $question) {
            $answer = $answers[$question->id] ?? null;
            $chosen = $answer?->chosenIndexes() ?? [];

            $isCorrect = $question->isCorrectChoice($chosen);
            $points = $isCorrect ? (float) $question->points : 0.0;

            if ($answer !== null) {
                $answer->update([
                    'is_correct' => $isCorrect,
                    'points_awarded' => $points,
                ]);
            }

            $score += $points;
        }

        $attempt->update([
            'score' => round($score, 2),
            'max_score' => round((float) $questions->sum('points'), 2),
            'status' => $expired
                ? QuizAttempt::STATUS_EXPIRED
                : QuizAttempt::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        return $attempt;
    }

    /**
     * Une question non répondue vaut zéro : la réponse est simplement absente
     * du bulletin, elle n'est pas comptée comme fausse dans un total d'erreurs.
     */
    public function isAnswered(QuizAttempt $attempt, FormField $question): bool
    {
        return $attempt->answers()->where('form_field_id', $question->id)->exists();
    }
}

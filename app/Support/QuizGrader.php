<?php

namespace App\Support;

use App\Models\FormField;
use App\Models\Grader;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use Illuminate\Support\Carbon;

/**
 * Correction d'une évaluation.
 *
 * Le calcul vit ici, et non dans un contrôleur, pour deux raisons : il est
 * testable seul, et surtout il n'existe qu'un seul endroit qui décide d'une
 * note — la page de résultat, le PDF et la liste de l'administrateur lisent
 * tous la même valeur stockée, ils ne la recalculent jamais.
 *
 * Deux familles de questions, deux régimes :
 *   - les questions à propositions sont corrigées automatiquement à la remise ;
 *   - les questions ouvertes reçoivent leur note plus tard, de l'enseignant. Tant
 *     qu'elles n'en ont pas, `points_awarded` reste `null` : « en attente » n'est
 *     pas « zéro point », et c'est cette différence qui permet de compter les
 *     copies à corriger.
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

        foreach ($questions as $question) {
            $answer = $answers[$question->id] ?? null;

            // Une question non répondue vaut zéro par absence : elle n'a pas de
            // ligne de réponse, il n'y a donc rien à écrire — exactement comme
            // avant ce lot.
            if ($answer === null) {
                continue;
            }

            // Question ouverte : aucune machine ne note un texte libre. Elle est
            // marquée « en attente » et non « zéro », sinon l'enseignant n'aurait
            // plus aucun moyen de retrouver ce qui lui reste à corriger.
            if ($question->isOpen()) {
                $answer->update(['is_correct' => null, 'points_awarded' => null]);

                continue;
            }

            $isCorrect = $question->isCorrectChoice($answer->chosenIndexes());

            $answer->update([
                'is_correct' => $isCorrect,
                'points_awarded' => $isCorrect ? (float) $question->points : 0.0,
            ]);
        }

        $attempt->update([
            'score' => $this->computedScore($attempt),
            'max_score' => round((float) $questions->sum('points'), 2),
            'status' => $expired
                ? QuizAttempt::STATUS_EXPIRED
                : QuizAttempt::STATUS_SUBMITTED,
            'submitted_at' => Carbon::now(),
        ]);

        return $attempt;
    }

    /**
     * Attribue les points d'une réponse rédigée, et son commentaire.
     *
     * La note est bornée au barème de la question : accepter davantage donnerait
     * une épreuve notée au-dessus de son total, ce qu'aucun jury n'accepte. Une
     * note partielle est possible — c'est l'intérêt d'une correction humaine — et
     * dans ce cas la réponse n'est pas déclarée « juste » : seul le barème entier
     * l'est.
     *
     * Le commentaire suit la note : le formulaire renvoie l'état complet de la
     * réponse, donc un champ vidé efface le commentaire précédent. C'est ce qui
     * permet de retirer une appréciation qu'on regrette, plutôt que de laisser
     * un texte qu'on ne peut plus enlever.
     *
     * `$by` désigne l'auteur de la note — un correcteur externe, ou un
     * administrateur (son identifiant). La distinction n'est pas décorative :
     * c'est elle qui permet à un correcteur de ne retrouver dans son export que
     * les copies qu'il a effectivement corrigées, et à une note contestée
     * d'avoir un auteur.
     */
    public function award(QuizAnswer $answer, float $points, ?string $comment = null, Grader|int|null $by = null): QuizAnswer
    {
        $question = $answer->field;
        $max = $question === null ? 0.0 : (float) $question->points;

        $points = max(0.0, min($points, $max));

        $answer->update([
            'points_awarded' => round($points, 2),
            'is_correct' => $points >= $max && $max > 0,
            'grader_comment' => self::normalizeComment($comment),
        ] + $this->attribution($answer, $by, overwrite: true));

        return $answer;
    }

    /**
     * Enregistre un commentaire sans attribuer de note.
     *
     * Un correcteur peut vouloir écrire une remarque avant de décider de la
     * note : la perdre silencieusement serait le pire des comportements. La
     * réponse reste donc « en attente » — le commentaire, lui, est conservé.
     *
     * L'attribution ne change que si la réponse n'en avait aucune : c'est le
     * premier qui a travaillé sur la copie qui reste l'auteur tant qu'une note
     * n'a pas été posée par quelqu'un d'autre.
     */
    public function comment(QuizAnswer $answer, ?string $comment, Grader|int|null $by = null): QuizAnswer
    {
        $answer->update([
            'grader_comment' => self::normalizeComment($comment),
        ] + $this->attribution($answer, $by, overwrite: false));

        return $answer;
    }

    private static function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : $comment;
    }

    /**
     * Colonnes d'attribution d'une note, selon qui l'a posée.
     *
     * `null` en retour signifie « ne touche à rien » : une réponse corrigée par
     * un correcteur, puis seulement commentée par un administrateur, garde son
     * auteur d'origine — sans quoi elle disparaîtrait de l'export du correcteur
     * sans que personne ne s'en aperçoive.
     *
     * @return array<string, int|null>
     */
    private function attribution(QuizAnswer $answer, Grader|int|null $by, bool $overwrite): array
    {
        if ($by === null) {
            return [];
        }

        $alreadyAttributed = $answer->graded_by_grader_id !== null
            || $answer->graded_by_admin_id !== null;

        if (! $overwrite && $alreadyAttributed) {
            return [];
        }

        return $by instanceof Grader
            ? ['graded_by_grader_id' => $by->getKey(), 'graded_by_admin_id' => null]
            : ['graded_by_grader_id' => null, 'graded_by_admin_id' => $by];
    }

    /**
     * Recalcule la note d'une copie après une correction manuelle.
     *
     * `score` est la somme de ce qui a été attribué, QCM automatiques compris :
     * une seule valeur stockée, donc aucun risque que l'affichage et l'export
     * racontent deux histoires différentes.
     */
    public function recompute(QuizAttempt $attempt): QuizAttempt
    {
        $attempt->update(['score' => $this->computedScore($attempt)]);

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

    /**
     * Somme des points attribués, en ignorant les réponses non encore corrigées.
     *
     * Les ignorer — et non les compter zéro — est ce qui rend la note provisoire
     * honnête : elle annonce ce qui est acquis, pas ce qui reste à décider.
     */
    private function computedScore(QuizAttempt $attempt): float
    {
        return round((float) $attempt->answers()->whereNotNull('points_awarded')->sum('points_awarded'), 2);
    }
}

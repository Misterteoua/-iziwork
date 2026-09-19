<?php

namespace App\Support;

use App\Models\FormField;
use Illuminate\Validation\ValidationException;

/**
 * Mise en forme d'une question : propositions vides retirées, bonnes réponses
 * remappées, type déduit du nombre de bonnes réponses.
 *
 * Ce code vit ici, et non dans le contrôleur, parce que deux chemins créent des
 * questions : la saisie manuelle et l'import d'un fichier Excel ou Word. Sans
 * point unique, les deux finiraient par accepter des choses différentes — et
 * c'est exactement le genre d'écart qui ne se voit qu'après une épreuve ratée.
 */
final class QuizQuestionData
{
    /** Nombre maximal de propositions par question, comme la saisie manuelle. */
    public const MAX_OPTIONS = 8;

    /**
     * Normalise une question et renvoie les attributs prêts pour `fields()->create`.
     *
     * @param  array<int, string>  $options  propositions dans l'ordre, vides conservées
     * @param  array<int, int>  $correct  index 0-based dans $options (pas dans le résultat)
     * @param  ?string  $type  type imposé (« radio » ou « checkbox ») ; null = déduit du nombre de bonnes réponses
     * @return array{field_label: string, field_type: string, options: array<int, string>, correct_answer: array<int, int>, points: float}
     */
    public static function normalize(string $label, array $options, array $correct, float $points = 1.0, ?string $type = null): array
    {
        $label = trim($label);

        if ($label === '') {
            throw ValidationException::withMessages(['field_label' => 'L\'énoncé de la question est obligatoire.']);
        }

        if (mb_strlen($label) > 2000) {
            throw ValidationException::withMessages(['field_label' => 'L\'énoncé d\'une question ne peut pas dépasser 2000 caractères.']);
        }

        if (count($options) > self::MAX_OPTIONS) {
            throw ValidationException::withMessages(['options' => 'Huit propositions au maximum par question.']);
        }

        // Index des propositions retenues : les vides sont écartées, mais la
        // correspondance avec les bonnes réponses est conservée. Sans ce
        // remappage, désigner « C » viserait la mauvaise proposition dès
        // qu'une case vide traîne avant elle.
        $kept = [];
        $remap = [];

        foreach (array_values($options) as $index => $option) {
            $option = trim((string) $option);

            if ($option === '') {
                continue;
            }

            if (mb_strlen($option) > 500) {
                throw ValidationException::withMessages(['options' => 'Une proposition ne peut pas dépasser 500 caractères.']);
            }

            $remap[$index] = count($kept);
            $kept[] = $option;
        }

        if (count($kept) < 2) {
            throw ValidationException::withMessages(['options' => 'Une question demande au moins deux propositions non vides.']);
        }

        $correct = array_values(array_unique(array_map(
            static fn ($index): int => $remap[(int) $index] ?? -1,
            $correct
        )));
        $correct = array_values(array_filter($correct, static fn (int $index): bool => $index >= 0));
        sort($correct);

        if ($correct === []) {
            throw ValidationException::withMessages(['correct' => 'Désignez la bonne réponse parmi les propositions non vides.']);
        }

        // Saisie manuelle : le type choisi par l'administrateur fait foi, et une
        // question à choix unique qui désignerait deux bonnes réponses est
        // refusée plutôt que transformée en silence. Import de fichier : le type
        // se déduit du nombre de bonnes réponses, faute de colonne pour le dire.
        if ($type === 'radio' && count($correct) > 1) {
            throw ValidationException::withMessages(['correct' => 'Une question à choix unique ne peut avoir qu\'une seule bonne réponse.']);
        }

        if (! in_array($type, FormField::QUESTION_TYPES, true)) {
            $type = count($correct) > 1 ? 'checkbox' : 'radio';
        }

        if ($points < 0.5 || $points > 100) {
            throw ValidationException::withMessages(['points' => 'Le barème doit être compris entre 0,5 et 100 points.']);
        }

        return [
            'field_label' => $label,
            'field_type' => $type,
            'options' => $kept,
            'correct_answer' => $correct,
            'points' => round($points, 2),
        ];
    }

    /**
     * Attributs d'un modèle à partir d'une question normalisée.
     *
     * @param  array{field_label: string, field_type: string, options: array<int, string>, correct_answer: array<int, int>, points: float}  $question
     * @return array<string, mixed>
     */
    public static function attributes(array $question, int $order): array
    {
        return [
            'field_label' => $question['field_label'],
            'field_type' => $question['field_type'],
            'required' => true,
            'order' => $order,
            'options' => $question['options'],
            'correct_answer' => $question['correct_answer'],
            'points' => $question['points'],
        ];
    }

    /**
     * Une question d'évaluation, ou null si le champ n'en est pas une.
     */
    public static function isQuestion(FormField $field): bool
    {
        return in_array($field->field_type, FormField::QUESTION_TYPES, true);
    }
}

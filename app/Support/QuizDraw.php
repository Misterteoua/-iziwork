<?php

namespace App\Support;

use App\Models\Form;
use App\Models\FormField;

/**
 * Tirage aléatoire : quelles questions, dans quel ordre, et dans quel ordre les
 * propositions de chacune.
 *
 * Le plan est calculé une seule fois, au démarrage de la participation, puis
 * enregistré sur celle-ci. C'est la condition pour que l'aléatoire soit utilisable
 * en examen : un tirage recalculé à chaque page donnerait à un candidat qui
 * recharge l'épreuve une deuxième série de questions, et rendrait ses réponses
 * déjà validées incompréhensibles.
 */
final class QuizDraw
{
    /**
     * @return array{question_order: array<int, int>, option_order: array<int, array<int, int>>, max_score: float}
     */
    public static function plan(Form $quiz): array
    {
        $questions = $quiz->quizQuestions()->get();

        $selected = self::select($quiz, $questions);

        $order = $selected->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return [
            'question_order' => $order,
            'option_order' => self::shuffleOptions($quiz, $selected),
            'max_score' => round((float) $selected->sum('points'), 2),
        ];
    }

    /**
     * Questions effectivement posées à ce candidat.
     *
     * Le tirage tire toujours un sous-ensemble au hasard quand le nombre demandé
     * est inférieur au total ; l'ordre, lui, n'est mélangé que si l'administrateur
     * l'a demandé. Un sous-ensemble pris dans l'ordre du questionnaire serait
     * prévisible, ce qui est justement ce que le tirage doit empêcher.
     *
     * @param  \Illuminate\Support\Collection<int, FormField>  $questions
     * @return \Illuminate\Support\Collection<int, FormField>
     */
    private static function select(Form $quiz, \Illuminate\Support\Collection $questions): \Illuminate\Support\Collection
    {
        $draw = $quiz->quizDrawCount();
        $shuffled = $questions->shuffle();

        if ($draw !== null && $draw < $questions->count()) {
            return $shuffled->take($draw)->values();
        }

        return $quiz->quizShufflesQuestions() ? $shuffled->values() : $questions->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FormField>  $questions
     * @return array<int, array<int, int>>
     */
    private static function shuffleOptions(Form $quiz, \Illuminate\Support\Collection $questions): array
    {
        if (! $quiz->quizShufflesOptions()) {
            return [];
        }

        $order = [];

        foreach ($questions as $question) {
            $count = count($question->getOptionsList());

            if ($count < 2) {
                continue;
            }

            $indexes = range(0, $count - 1);
            shuffle($indexes);

            $order[(int) $question->id] = $indexes;
        }

        return $order;
    }
}

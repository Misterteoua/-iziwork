<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une passe de renotation : qui l'a lancée, quand, et sur combien de copies.
 *
 * C'est la mémoire des notes qui ont bougé **sans qu'un humain ne les signe**.
 * Trois gestes y laissent une ligne : le recalcul demandé depuis la page des
 * résultats, la renotation qui accompagne l'enregistrement d'une question
 * modifiée, et la remise d'aplomb qui suit la suppression d'une question. Les
 * décisions humaines, elles, ont leur propre journal — celui de la copie — et un
 * recalcul n'en est pas une.
 *
 * Le nombre de copies est celui qui a été annoncé à l'enseignant : les copies
 * dont la note ou le barème a bougé. Un recalcul demandé se trace même quand il
 * ne change rien ; une question enregistrée ne se trace que si des copies ont
 * changé, parce que corriger un énoncé n'est pas un recalcul.
 *
 * Avec le compte, le journal garde la **liste** : quelles copies, et leur note
 * avant et après. C'est l'aperçu qui a montré ces valeurs avant le clic ; elles
 * sont écrites telles quelles, et ce sont donc exactement celles que le recalcul
 * a produites — le calcul est le même, au même endroit.
 */
class QuizRegradeRun extends Model
{
    /** Le recalcul des notes demandé depuis les résultats. */
    public const ORIGIN_QUIZ = 'quiz';

    /** La renotation qui accompagne l'enregistrement d'une question modifiée. */
    public const ORIGIN_QUESTION = 'question';

    /** La remise d'aplomb qui suit la suppression d'une question. */
    public const ORIGIN_DELETED = 'deleted';

    protected $fillable = [
        'form_id',
        'admin_user_id',
        'origin',
        'form_field_id',
        'question_label',
        'copies',
        'copies_detail',
    ];

    protected function casts(): array
    {
        return [
            'admin_user_id' => 'integer',
            'form_field_id' => 'integer',
            'copies' => 'integer',
            'copies_detail' => 'array',
        ];
    }

    /**
     * Le détail tel que le journal le conserve, à partir de l'aperçu d'un
     * recalcul.
     *
     * Les lignes de l'aperçu portent le modèle de la participation ; ce qui est
     * écrit ici est ce qui reste lisible après coup : sa référence — jamais son
     * nom, qu'une évaluation anonyme ne doit pas voir traîner —, son identifiant,
     * et les valeurs avant/après.
     *
     * @param  array<int, array{attempt: QuizAttempt, from: float|null, to: float, max_from: float|null, max_to: float, answers: int, released: int, reclaimed: int}>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function detailFrom(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'attempt_id' => (int) $row['attempt']->id,
            'reference' => (string) $row['attempt']->reference,
            'from' => $row['from'],
            'to' => $row['to'],
            'max_from' => $row['max_from'],
            'max_to' => $row['max_to'],
            'answers' => (int) $row['answers'],
            'released' => (int) $row['released'],
            'reclaimed' => (int) $row['reclaimed'],
        ], $rows);
    }

    /**
     * Les copies de ce recalcul, dans l'ordre où l'aperçu les a annoncées.
     *
     * C'est la référence qui classe, comme sur la page d'aperçu : le journal ne
     * réordonne pas ce que l'enseignant a lu avant de confirmer.
     *
     * Les notes ressortent en nombres : JSON ne distingue pas `3` de `3.0`, et un
     * entier glissé au milieu d'un calcul de notes serait un piège — c'est la
     * comparaison stricte avant/après qui décide du sens de la flèche.
     *
     * @return array<int, array<string, mixed>>
     */
    public function changedCopies(): array
    {
        return array_map(static function (array $copy): array {
            $copy['attempt_id'] = (int) ($copy['attempt_id'] ?? 0);
            $copy['reference'] = (string) ($copy['reference'] ?? '');
            $copy['answers'] = (int) ($copy['answers'] ?? 0);
            $copy['released'] = (int) ($copy['released'] ?? 0);
            $copy['reclaimed'] = (int) ($copy['reclaimed'] ?? 0);

            foreach (['from', 'to', 'max_from', 'max_to'] as $key) {
                $copy[$key] = ($copy[$key] ?? null) === null ? null : (float) $copy[$key];
            }

            return $copy;
        }, $this->copies_detail ?? []);
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Le compte d'administration qui a lancé le recalcul.
     *
     * Simple trace : la relation est déclarée ici, mais aucune clé étrangère ne
     * la retient en base — supprimer un compte ne doit pas effacer l'histoire de
     * ce qu'il a recalculé.
     */
    public function admin()
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /** La question en cause, tant qu'elle existe encore. */
    public function question()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** L'origine du recalcul, telle que le journal la lit. */
    public function originLabel(): string
    {
        return match ($this->origin) {
            self::ORIGIN_QUIZ => 'Recalcul demandé',
            self::ORIGIN_QUESTION => 'Question modifiée',
            self::ORIGIN_DELETED => 'Question supprimée',
            default => 'Recalcul',
        };
    }

    /**
     * Qui a lancé le recalcul, en clair.
     *
     * Un compte supprimé depuis laisse la ligne en place et le dit, plutôt que
     * de laisser un blanc qu'on prendrait pour une donnée perdue.
     */
    public function actorLabel(): string
    {
        if ($this->admin_user_id === null) {
            return 'Administration';
        }

        return $this->admin?->username ?? 'compte supprimé';
    }
}

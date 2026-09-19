<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Réponse d'un candidat à une question.
 */
class QuizAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'form_field_id',
        'choice',
        'answer_text',
        'is_correct',
        'points_awarded',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'choice' => 'array',
            'is_correct' => 'boolean',
            'points_awarded' => 'decimal:2',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Cette réponse a-t-elle été notée ?
     *
     * `null` n'est pas zéro : une question ouverte attend une note, une question
     * à propositions est notée dès la remise de la copie. Toute la logique de
     * « reste-t-il des copies à corriger » repose sur cette distinction.
     */
    public function isGraded(): bool
    {
        return $this->points_awarded !== null;
    }

    /**
     * Note attribuée, lisible telle quelle. Une réponse non encore corrigée rend
     * `null`, jamais 0 : l'affichage doit pouvoir dire « en attente ».
     */
    public function awardedPoints(): ?float
    {
        return $this->isGraded() ? (float) $this->points_awarded : null;
    }

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function field()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /**
     * Index des options choisies, en entiers, triés.
     *
     * @return array<int, int>
     */
    public function chosenIndexes(): array
    {
        $choice = $this->choice ?? [];

        $indexes = array_map('intval', array_filter($choice, 'is_numeric'));

        sort($indexes);

        return $indexes;
    }
}

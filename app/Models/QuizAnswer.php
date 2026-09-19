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

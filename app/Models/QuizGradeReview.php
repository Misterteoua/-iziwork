<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un acte de relecture : une note remplacée, ou une note confirmée après examen.
 *
 * C'est la mémoire du second niveau de relecture. Quand l'administration reprend
 * la note d'un correcteur, la ligne dit ce qu'il avait mis, qui l'a repris, quand
 * et pourquoi. Quand elle la maintient après examen, la ligne dit la même chose
 * — sauf que rien n'a changé : la note reste au nom du correcteur, et la revue
 * n'en est pas moins tracée.
 */
class QuizGradeReview extends Model
{
    protected $fillable = [
        'quiz_answer_id',
        'previous_points',
        'previous_comment',
        'previous_grader_id',
        'previous_admin_id',
        'reviewed_by_admin_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_points' => 'decimal:2',
            'previous_grader_id' => 'integer',
            'previous_admin_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
        ];
    }

    public function answer()
    {
        return $this->belongsTo(QuizAnswer::class, 'quiz_answer_id');
    }

    /** Le correcteur dont la note a été examinée. */
    public function previousGrader()
    {
        return $this->belongsTo(Grader::class, 'previous_grader_id');
    }

    /** L'administrateur qui a relu. */
    public function reviewer()
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }

    /** L'administrateur dont la note a été remplacée, s'il en était l'auteur. */
    public function previousAdmin()
    {
        return $this->belongsTo(AdminUser::class, 'previous_admin_id');
    }

    /**
     * Auteur de la note remplacée, en clair : le nom du correcteur, celui de
     * l'administrateur, ou rien pour une note antérieure au suivi des auteurs.
     *
     * Les deux relations sont censées avoir été chargées d'avance (voir les
     * contrôleurs) : une copie de cinquante questions ne doit pas déclencher
     * cinquante requêtes pour afficher trois noms.
     */
    public function previousAuthorLabel(): ?string
    {
        if ($this->previous_grader_id !== null) {
            $name = $this->previousGrader?->name;

            return $name === null ? 'correcteur supprimé' : $name.' (correcteur)';
        }

        if ($this->previous_admin_id !== null) {
            $username = $this->previousAdmin?->username;

            return $username === null ? 'administration' : $username.' (admin)';
        }

        return null;
    }
}

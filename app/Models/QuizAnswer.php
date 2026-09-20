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
        'grader_comment',
        'graded_by_grader_id',
        'graded_by_admin_id',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'choice' => 'array',
            'is_correct' => 'boolean',
            'points_awarded' => 'decimal:2',
            'graded_by_grader_id' => 'integer',
            'graded_by_admin_id' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Réponses rédigées qui attendent une note.
     *
     * Le filtre vit ici, et non dans un contrôleur : la page des résultats, la
     * correction d'une copie et l'enchaînement des copies à corriger doivent
     * compter exactement la même chose, sans quoi l'une annoncerait « 3 copies à
     * corriger » quand l'autre n'en proposerait que deux.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<QuizAnswer>  $query
     */
    public function scopePendingManual($query)
    {
        return $query->whereNull('points_awarded')
            ->whereHas('field', fn ($field) => $field->where('field_type', FormField::OPEN_TYPE));
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

    /**
     * Un commentaire a-t-il été laissé sur cette réponse ?
     *
     * Un commentaire vide n'est pas un commentaire : une zone blanche laissée
     * dans le formulaire ne doit pas produire un cadre vide dans le bulletin de
     * l'étudiant.
     */
    public function hasComment(): bool
    {
        return trim((string) $this->grader_comment) !== '';
    }

    /**
     * Qui a noté cette réponse : le nom du correcteur, celui de
     * l'administrateur, ou rien du tout.
     *
     * `null` n'est pas une anomalie : les copies corrigées avant ce lot n'ont
     * pas d'auteur enregistré. L'affichage les annonce comme telles plutôt que
     * de leur en inventer un.
     */
    public function gradedByLabel(): ?string
    {
        if ($this->graded_by_grader_id !== null) {
            $grader = $this->grader;

            return $grader === null
                ? 'correcteur supprimé'
                : $grader->name.' (correcteur)';
        }

        if ($this->graded_by_admin_id !== null) {
            $admin = $this->gradingAdmin;

            return $admin === null ? 'administration' : $admin->username.' (admin)';
        }

        return null;
    }

    /**
     * Les relectures de cette note, de la plus ancienne à la plus récente.
     *
     * Une ligne par note remplacée — ou confirmée après examen. Rien n'est
     * effacé : c'est ce journal qui permet de répondre à « qui avait mis quoi,
     * et pourquoi c'est changé ».
     */
    public function reviews()
    {
        return $this->hasMany(QuizGradeReview::class, 'quiz_answer_id')->orderBy('id');
    }

    /** La dernière relecture connue, s'il y en a une. */
    public function latestReview(): ?QuizGradeReview
    {
        return $this->relationLoaded('reviews')
            ? $this->reviews->last()
            : $this->reviews()->latest('id')->first();
    }

    /**
     * La note a-t-elle été relue par l'administration ?
     *
     * La question est précise : un correcteur qui retouche sa propre note laisse
     * lui aussi une ligne au journal, mais cela ne fait pas de lui une relecture
     * d'administration — et surtout, cela ne doit pas lui fermer l'accès à sa
     * propre copie.
     */
    public function reviewedByAdmin(): bool
    {
        return $this->relationLoaded('reviews')
            ? $this->reviews->contains(fn (QuizGradeReview $review): bool => $review->reviewed_by_admin_id !== null)
            : $this->reviews()->whereNotNull('reviewed_by_admin_id')->exists();
    }

    /** La note actuellement retenue a-t-elle été posée par un correcteur externe ? */
    public function heldByGrader(): bool
    {
        return $this->graded_by_grader_id !== null;
    }

    /** Le correcteur externe auteur de la note ou du commentaire. */
    public function grader()
    {
        return $this->belongsTo(Grader::class, 'graded_by_grader_id');
    }

    /** L'administrateur auteur de la note. */
    public function gradingAdmin()
    {
        return $this->belongsTo(AdminUser::class, 'graded_by_admin_id');
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

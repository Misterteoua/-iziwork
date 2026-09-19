<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;

    /** Dépôt de travaux : le comportement historique. */
    public const TYPE_DEPOSIT = 'deposit';

    /** Évaluation en ligne (questionnaire noté et chronométré). */
    public const TYPE_QUIZ = 'quiz';

    /** Réglages par défaut d'une évaluation. */
    private const DEFAULT_QUIZ_SETTINGS = [
        'duration_minutes' => 30,
        'show_score' => true,
        'proctoring' => true,
        // Tirage aléatoire : désactivé par défaut, pour qu'une évaluation créée
        // sans y penser reste celle que son auteur a écrite, question par question.
        'draw_count' => null,
        'shuffle_questions' => false,
        'shuffle_options' => false,
    ];

    protected $fillable = [
        'title',
        'description',
        'token',
        'open_date',
        'close_date',
        'max_submissions',
        'is_anonymous',
        'status',
        'type',
        'quiz_settings',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'open_date' => 'datetime',
            'close_date' => 'datetime',
            'is_anonymous' => 'boolean',
            'quiz_settings' => 'array',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($form) {
            if (empty($form->token)) {
                $form->token = Str::random(32);
            }
        });
    }

    public function fields()
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function isOpen(): bool
    {
        $now = now();
        
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->open_date && $now->lt($this->open_date)) {
            return false;
        }

        if ($this->close_date && $now->gt($this->close_date)) {
            return false;
        }

        if ($this->max_submissions && $this->submissions()->count() >= $this->max_submissions) {
            return false;
        }

        return true;
    }

    public function getSubmissionCount(): int
    {
        return $this->submissions()->count();
    }

    // ------------------------------------------------------------------ Quiz

    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * S'agit-il d'une évaluation en ligne ?
     *
     * Le module de dépôt de travaux s'appuie sur cette question pour ignorer
     * les évaluations, et inversement : les deux parcours ne se croisent pas.
     */
    public function isQuiz(): bool
    {
        return $this->type === self::TYPE_QUIZ;
    }

    /**
     * Réglages de l'évaluation, complétés par les valeurs par défaut.
     *
     * @return array{duration_minutes: int, show_score: bool, proctoring: bool}
     */
    public function quizSettings(): array
    {
        return array_merge(self::DEFAULT_QUIZ_SETTINGS, $this->quiz_settings ?? []);
    }

    /**
     * Durée de l'épreuve en minutes (bornée : une durée absurde viderait
     * l'épreuve avant qu'elle ne commence).
     */
    public function quizDurationMinutes(): int
    {
        return max(1, min(600, (int) $this->quizSettings()['duration_minutes']));
    }

    public function quizShowsScore(): bool
    {
        return (bool) $this->quizSettings()['show_score'];
    }

    public function quizUsesProctoring(): bool
    {
        return (bool) $this->quizSettings()['proctoring'];
    }

    /**
     * Nombre de questions posées à chaque candidat, ou null si toutes le sont.
     *
     * Une valeur supérieure à la banque de questions est ramenée au total : on
     * ne peut pas poser plus de questions qu'il n'en existe.
     */
    public function quizDrawCount(): ?int
    {
        $count = (int) ($this->quizSettings()['draw_count'] ?? 0);

        return $count > 0 ? $count : null;
    }

    /** Le même questionnaire dans un ordre différent pour chaque candidat. */
    public function quizShufflesQuestions(): bool
    {
        return (bool) $this->quizSettings()['shuffle_questions'];
    }

    /** Les propositions mélangées (A, B, C, D) pour chaque candidat. */
    public function quizShufflesOptions(): bool
    {
        return (bool) $this->quizSettings()['shuffle_options'];
    }

    /**
     * Questions de l'évaluation, dans l'ordre prévu.
     *
     * @return \Illuminate\Database\Eloquent\Builder<FormField>
     */
    public function quizQuestions()
    {
        return $this->fields()
            ->whereIn('field_type', FormField::QUESTION_TYPES)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * Une évaluation est-elle ouverte aux étudiants ?
     *
     * Même logique que isOpen(), mais le quota compte les participations et
     * non les soumissions : une évaluation n'a pas de dépôt de fichiers. Les
     * deux méthodes coexistent plutôt que de fusionner, pour ne pas changer le
     * comportement du dépôt de travaux.
     */
    public function quizIsOpen(): bool
    {
        $now = now();

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->open_date && $now->lt($this->open_date)) {
            return false;
        }

        if ($this->close_date && $now->gt($this->close_date)) {
            return false;
        }

        if ($this->max_submissions) {
            $engaged = $this->attempts()
                ->whereIn('status', [
                    QuizAttempt::STATUS_IN_PROGRESS,
                    QuizAttempt::STATUS_SUBMITTED,
                    QuizAttempt::STATUS_EXPIRED,
                ])
                ->count();

            if ($engaged >= $this->max_submissions) {
                return false;
            }
        }

        return true;
    }

    /**
     * Total des points de l'évaluation, barème des questions additionné.
     */
    public function quizMaxScore(): float
    {
        return (float) $this->quizQuestions()->sum('points');
    }

    /**
     * L'administration a-t-elle préparé des références ?
     *
     * Si oui, l'étudiant doit saisir la sienne et une référence inconnue est
     * refusée. Sinon l'évaluation est en mode libre : l'étudiant s'identifie,
     * et une référence lui est attribuée à ce moment-là.
     */
    public function quizHasPreparedReferences(): bool
    {
        return $this->attempts()->exists();
    }
}

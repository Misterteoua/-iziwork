<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Participation d'un étudiant à une évaluation.
 *
 * Le chronomètre ne dépend jamais du navigateur : expires_at est fixée une
 * seule fois, au démarrage, à partir de la durée choisie par l'administrateur.
 * Recharger la page, fermer l'onglet ou modifier le JavaScript ne la déplace
 * pas.
 */
class QuizAttempt extends Model
{
    use HasFactory;

    /** Statuts d'une participation. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'form_id',
        'reference',
        'student_name',
        'student_email',
        'student_major',
        'status',
        'started_at',
        'expires_at',
        'submitted_at',
        'score',
        'max_score',
        'infractions',
        'infraction_count',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'infractions' => 'array',
            'infraction_count' => 'integer',
        ];
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_EXPIRED], true);
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Le temps imparti est-il écoulé ?
     *
     * Un compte à rebours affiché à zéro ne suffit pas : c'est cette méthode
     * qui décide, côté serveur, à chaque affichage de question comme à la
     * soumission.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && Carbon::now()->greaterThan($this->expires_at);
    }

    /** Secondes restantes, jamais négatives. */
    public function remainingSeconds(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, (int) Carbon::now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * Temps réellement passé sur l'épreuve, en minutes et secondes.
     */
    public function elapsedSeconds(): int
    {
        if ($this->started_at === null) {
            return 0;
        }

        $end = $this->submitted_at ?? Carbon::now();

        return max(0, (int) $this->started_at->diffInSeconds($end, false));
    }

    /**
     * Identifiant affiché à l'administrateur et à l'étudiant : la référence,
     * puisque c'est elle qui fait office de login.
     */
    public function displayName(): ?string
    {
        return $this->student_name ?: ($this->form?->is_anonymous ? null : $this->reference);
    }

    /**
     * Ajoute une infraction au journal (perte de focus, sortie de plein écran).
     */
    public function recordInfraction(string $type, ?string $detail = null): void
    {
        $journal = $this->infractions ?? [];

        $journal[] = [
            'type' => $type,
            'detail' => $detail,
            'at' => Carbon::now()->toDateTimeString(),
            'elapsed' => $this->elapsedSeconds(),
        ];

        // Les journaux ne doivent pas gonfler indéfiniment : au-delà de cent
        // entrées, la cause est connue, la cent unième n'apprend rien.
        if (count($journal) > 100) {
            $journal = array_slice($journal, -100);
        }

        $this->forceFill([
            'infractions' => $journal,
            'infraction_count' => $this->infraction_count + 1,
        ])->save();
    }

    /**
     * Questions de l'évaluation, dans l'ordre prévu par l'administrateur.
     *
     * @return Collection<int, FormField>
     */
    public function questions(): Collection
    {
        return $this->form->quizQuestions()->get();
    }
}

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
        'question_order',
        'option_order',
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
            'question_order' => 'array',
            'option_order' => 'array',
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

    /**
     * Réponses rédigées qui attendent encore une note de l'enseignant.
     *
     * Une question sans réponse n'y figure pas : il n'y a rien à corriger, la
     * question vaut zéro par absence, comme pour un QCM non répondu.
     */
    public function pendingOpenAnswers()
    {
        return $this->answers()
            ->whereHas('field', fn ($query) => $query->where('field_type', FormField::OPEN_TYPE))
            ->whereNull('points_awarded');
    }

    /** Nombre de réponses rédigées encore à corriger. */
    public function pendingManualCount(): int
    {
        return $this->pendingOpenAnswers()->count();
    }

    /**
     * La copie attend-elle une correction de l'enseignant ?
     *
     * Seules les copies remises sont concernées : une épreuve en cours n'a rien
     * à corriger, et une copie entièrement composée de QCM est notée d'emblée.
     */
    public function awaitsManualGrading(): bool
    {
        return $this->isFinished() && $this->pendingManualCount() > 0;
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
     * Questions posées à ce candidat, dans l'ordre qu'il a reçu.
     *
     * Sans plan enregistré (participation créée avant le tirage aléatoire, ou
     * reprise d'une épreuve en cours), l'ordre naturel de l'administrateur
     * s'applique : c'est exactement ce que le candidat voyait déjà.
     *
     * @return Collection<int, FormField>
     */
    public function questions(): Collection
    {
        $questions = $this->form->quizQuestions()->get();
        $order = $this->question_order;

        if (! is_array($order) || $order === []) {
            return $questions;
        }

        // Une question supprimée pendant l'épreuve disparaît simplement du
        // parcours : le candidat passe à la suivante au lieu de voir un trou.
        $byId = $questions->keyBy('id');

        return collect($order)
            ->map(static fn ($id): ?FormField => $byId->get((int) $id))
            ->filter()
            ->values();
    }

    /**
     * Propositions dans l'ordre vu par le candidat.
     *
     * Chaque entrée porte son index d'origine : le formulaire envoyé utilise cet
     * index, donc la correction reste indépendante du mélange.
     *
     * @return array<int, array{original: int, label: string}>
     */
    public function displayOptions(FormField $question): array
    {
        $options = $question->getOptionsList();
        $order = $this->displayOrder($question);

        $display = [];

        foreach ($order as $original) {
            $display[] = [
                'original' => $original,
                'label' => (string) ($options[$original] ?? ''),
            ];
        }

        return $display;
    }

    /**
     * Index d'origine des propositions, dans l'ordre d'affichage.
     *
     * @return array<int, int>
     */
    public function displayOrder(FormField $question): array
    {
        $count = count($question->getOptionsList());
        $natural = $count > 0 ? range(0, $count - 1) : [];
        $stored = $this->option_order[(string) $question->id] ?? $this->option_order[$question->id] ?? null;

        // Un ordre enregistré qui ne serait pas une permutation exacte des
        // propositions est ignoré : mieux vaut l'ordre naturel qu'une proposition
        // manquante ou affichée deux fois devant un candidat.
        if (! is_array($stored)) {
            return $natural;
        }

        $stored = array_values(array_unique(array_map('intval', $stored)));

        // Attention : on compare l'ensemble trié, mais on renvoie l'ordre reçu.
        // Trier la valeur retournée remettrait les propositions dans l'ordre
        // d'origine et annulerait silencieusement le mélange.
        $asSet = $stored;
        sort($asSet);

        return $asSet === $natural ? $stored : $natural;
    }
}

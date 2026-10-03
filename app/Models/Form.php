<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
        // Mode d'accès : libre par défaut. Un import de liste ou une génération
        // de références le bascule en « liste préparée » — et il ne rebascule
        // jamais tout seul, ce qui compte : c'est ce qui garantit qu'un candidat
        // suivant, sur le même poste, n'est pas mis dehors après la première copie.
        'requires_reference' => false,
        // Anti-doublon par appareil : désactivé par défaut, et volontairement.
        // Une salle informatique partage les postes : l'activer là-bas
        // bloquerait le candidat suivant, ce qui est exactement ce que le
        // parcours « étudiant suivant » existe pour éviter.
        'one_attempt_per_device' => false,
        // Publication des corrections : aucune date choisie par défaut, donc la
        // fermeture de l'évaluation (ou sa désactivation) décide. Une date
        // explicite sert aux épreuves qui restent ouvertes plusieurs jours alors
        // que tous les candidats ont déjà composé : on publie sans attendre.
        'reveal_answers_at' => null,
        // Correction des questions à propositions : automatique par défaut, et
        // volontairement. Une évaluation déjà réglée avant que cette option
        // existe garde donc exactement le comportement qu'elle avait — aucune
        // note ne change sous les pieds d'un enseignant. Activé, le réglage
        // retire l'auto-correction : les QCM rejoignent la file de correction
        // manuelle, au même titre qu'une réponse rédigée.
        'manual_choice_grading' => false,
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

    /** Les liens courts de ce formulaire : accès, et un par copie évaluée. */
    public function shortLinks()
    {
        return $this->hasMany(ShortLink::class);
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
     * Les correcteurs externes affectés à cette évaluation.
     *
     * L'affectation est le seul périmètre d'un correcteur : il ne voit que les
     * copies des évaluations listées ici, et rien d'autre.
     */
    public function graders()
    {
        return $this->belongsToMany(Grader::class, 'grader_assignments')
            ->withPivot('created_by')
            ->withTimestamps();
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

    /**
     * Les questions à propositions sont-elles corrigées à la main ?
     *
     * Désactivé par défaut : sans ce réglage, un QCM est noté à la remise.
     * Activé, l'auto-correction est retirée et l'enseignant note lui-même les
     * questions à choix — utile quand plusieurs réponses sont défendables, ou
     * quand la question sert à faire argumenter plutôt qu'à cocher.
     */
    public function quizGradesChoiceManually(): bool
    {
        return (bool) $this->quizSettings()['manual_choice_grading'];
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
     * Une même empreinte d'appareil n'a droit qu'à une seule copie.
     *
     * Réglage d'évaluation, désactivé par défaut : c'est un verrou pour une
     * épreuve passée à distance, jamais pour une salle de machines où le poste
     * change de candidat d'une heure à l'autre.
     */
    public function quizBlocksSameDevice(): bool
    {
        return (bool) $this->quizSettings()['one_attempt_per_device'];
    }

    /**
     * Questions de l'évaluation, dans l'ordre prévu.
     *
     * @return \Illuminate\Database\Eloquent\Builder<FormField>
     */
    public function quizQuestions()
    {
        // ANSWER_TYPES et non QUESTION_TYPES : une question à réponse rédigée est
        // une question. Elle doit entrer dans le barème, dans le tirage et dans le
        // décompte, même si sa correction est manuelle.
        return $this->fields()
            ->whereIn('field_type', FormField::ANSWER_TYPES)
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
     * L'évaluation a-t-elle le droit de publier ses corrections ?
     *
     * Une évaluation ne publie ses réponses que lorsqu'elle ne peut plus
     * accueillir personne : la date de fermeture est dépassée, ou elle a été
     * désactivée. C'est une règle de sécurité, pas un confort d'affichage —
     * publier le détail d'une copie pendant que d'autres candidats composent
     * encore (même salle, épreuve étalée sur la journée) revient à leur donner
     * les réponses. L'auto-correction n'y change rien : elle sait la note bien
     * avant que l'épreuve ne soit finie pour tout le monde.
     *
     * Trois leviers, tous trois entre les mains de l'enseignant : une **date de
     * publication** choisie (utile quand tout le monde a composé alors que
     * l'épreuve reste ouverte plusieurs jours), la **date de fermeture**, et le
     * bouton « Fermer l'évaluation ». La première échéance atteinte publie. Aucune
     * donnée n'est supprimée : le détail est masqué, puis reparaît de lui-même,
     * sans intervention, à la première consultation qui suit la publication.
     */
    public function quizRevealsCorrection(): bool
    {
        $chosen = $this->quizPublicationDate();

        if ($chosen !== null && now()->greaterThanOrEqualTo($chosen)) {
            return true;
        }

        if ($this->close_date && now()->gt($this->close_date)) {
            return true;
        }

        return $this->status !== 'active';
    }

    /**
     * La date de publication choisie pour cette évaluation, ou null.
     *
     * Elle vit dans `quiz_settings`, comme les autres réglages : une évaluation
     * qui n'en a pas reste régie par sa fermeture. Une valeur illisible est
     * ignorée plutôt que de faire échouer une page de résultat — la règle de
     * fermeture reprend alors la main.
     */
    public function quizPublicationDate(): ?Carbon
    {
        $chosen = $this->quizSettings()['reveal_answers_at'] ?? null;

        if (! is_string($chosen) || trim($chosen) === '') {
            return null;
        }

        try {
            return Carbon::parse($chosen);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Le prochain moment où les corrections deviendront publiques.
     *
     * C'est la plus proche des deux échéances connues — la date de publication
     * choisie, la date de fermeture — pour l'annoncer à l'étudiant sans lui
     * promettre une date déjà passée. Retourne null quand aucune n'est fixée :
     * seul un changement d'état publiera alors les corrections.
     */
    public function quizRevealMoment(): ?Carbon
    {
        $moments = array_filter([$this->quizPublicationDate(), $this->close_date]);

        $future = array_filter($moments, fn (Carbon $moment): bool => $moment->isFuture());

        usort($future, fn (Carbon $a, Carbon $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $future[0] ?? null;
    }

    /**
     * Total des points de l'évaluation, barème des questions additionné.
     *
     * Les questions ouvertes y comptent : une épreuve notée sur 20 dont 8 points
     * relèvent de la rédaction est bien notée sur 20, pas sur 12.
     */
    public function quizMaxScore(): float
    {
        return (float) $this->quizQuestions()->sum('points');
    }

    /**
     * L'évaluation comporte-t-elle au moins une question à réponse rédigée ?
     *
     * C'est ce qui décide si une copie peut être « en attente de correction ».
     */
    public function quizHasOpenQuestions(): bool
    {
        return $this->quizQuestions()->where('field_type', FormField::OPEN_TYPE)->exists();
    }
    /**
     * L'administration a-t-elle préparé des références ?
     *
     * Si oui, l'étudiant doit saisir la sienne et une référence inconnue est
     * refusée. Sinon l'évaluation est en mode libre : l'étudiant s'identifie,
     * et une référence lui est attribuée à ce moment-là.
     *
     * C'est un **réglage**, et non un décompte des participations. La différence
     * n'est pas cosmétique : compter les participations faisait basculer en mode
     * liste dès la première copie rendue, si bien que le deuxième étudiant du
     * même poste — en mode libre — se voyait réclamer une référence qu'il n'avait
     * jamais reçue. Impossible de commencer.
     */
    public function quizHasPreparedReferences(): bool
    {
        // Réglage enregistré : il fait foi, même après que toutes les copies ont
        // été rendues ou réinitialisées.
        if (array_key_exists('requires_reference', $this->quiz_settings ?? [])) {
            return (bool) $this->quizSettings()['requires_reference'];
        }

        // Évaluation créée avant que ce réglage existe : on retombe sur l'indice
        // d'origine, resserré pour ne pas confondre une liste préparée avec les
        // copies déjà commencées par les étudiants.
        return $this->attempts()
            ->where(fn ($query) => $query
                ->whereNull('started_at')
                ->orWhere('status', QuizAttempt::STATUS_PENDING))
            ->exists();
    }

    /**
     * Fige l'évaluation en mode « liste préparée ».
     *
     * Appelé dès qu'une référence est créée pour un étudiant nommé ou générée en
     * série : à partir de là, l'entrée se fait par référence, définitivement.
     */
    public function markReferencesPrepared(): void
    {
        $this->update([
            'quiz_settings' => array_merge($this->quizSettings(), ['requires_reference' => true]),
        ]);
    }
}

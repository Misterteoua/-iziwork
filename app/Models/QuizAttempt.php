<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Seuil du rappel de fin d'épreuve : cinq minutes.
     *
     * Une seule constante pour le seuil et pour la phrase qui l'annonce : le
     * rappel se déclenche à l'écran comme il se lit, et les faire diverger
     * demanderait deux modifications au lieu d'une.
     */
    public const REMINDER_SECONDS = 300;

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
        'device_token',
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
     * Pièces jointes déposées avec les réponses de cette copie.
     *
     * Chargées sur la copie plutôt que sur chaque réponse : une correction lit
     * toutes les questions d'un coup, et une relation par réponse multiplierait
     * les requêtes sur une copie de cinquante questions.
     */
    public function attachments()
    {
        return $this->hasMany(QuizAttachment::class, 'quiz_attempt_id');
    }

    /**
     * Les pièces jointes d'une question, dans l'ordre de dépôt.
     *
     * @return Collection<int, QuizAttachment>
     */
    public function attachmentsFor(FormField $question): Collection
    {
        if ($this->relationLoaded('attachments')) {
            return $this->attachments
                ->where('form_field_id', $question->id)
                ->values();
        }

        return $this->attachments()
            ->where('form_field_id', $question->id)
            ->orderBy('id')
            ->get();
    }

    /** Les réponses rédigées de cette copie, dans l'ordre des questions. */
    public function openAnswers()
    {
        return $this->answers()->whereHas(
            'field',
            fn ($field) => $field->where('field_type', FormField::OPEN_TYPE)
        );
    }

    /**
     * Réponses rédigées qui attendent encore une note de l'enseignant.
     *
     * Une question sans réponse n'y figure pas : il n'y a rien à corriger, la
     * question vaut zéro par absence, comme pour un QCM non répondu.
     */
    public function pendingOpenAnswers()
    {
        return $this->answers()->pendingManual();
    }

    /**
     * Copies qui attendent encore une correction, dans l'ordre où l'enseignant
     * les corrige : de la première remise à la dernière.
     *
     * L'identifiant départage à la seconde près : deux copies rendues dans la même
     * seconde — un début de séance, ou une horloge figée — doivent garder l'ordre
     * de leur remise, et non un ordre tiré d'une référence aléatoire.
     *
     * @param  Builder<QuizAttempt>  $query
     */
    public function scopeAwaitsManualGrading($query)
    {
        return $query
            ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_EXPIRED])
            ->whereHas('answers', fn ($answers) => $answers->pendingManual())
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    /** Nombre de réponses rédigées encore à corriger. */
    public function pendingManualCount(): int
    {
        return $this->pendingOpenAnswers()->count();
    }

    /**
     * La copie est-elle entièrement corrigée ?
     *
     * C'est la condition d'affichage des commentaires à l'étudiant : un
     * commentaire écrit pendant qu'une autre rédaction attend encore une note
     * n'est pas forcément définitif, et le montrer ferait lire à l'étudiant une
     * appréciation que le correcteur aurait voulu nuancer ensuite.
     */
    public function isFullyGraded(): bool
    {
        return $this->isFinished() && $this->pendingManualCount() === 0;
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
     * Le moment où la copie doit s'arrêter : le chrono de l'épreuve ou la
     * fermeture de l'évaluation, au plus tôt des deux.
     *
     * Le chrono seul ne suffit pas : une épreuve qui ferme à 10 h doit arrêter
     * les copies encore ouvertes à 10 h, même si leur heure individuelle court
     * jusqu'à 10 h 20. C'est l'heure annoncée aux étudiants qui fait foi, et
     * c'est elle que le surveillant a en tête.
     *
     * La désactivation de l'évaluation n'entre pas dans ce calcul. Elle arrête
     * les entrées (voir {@see Form::quizIsOpen()}), mais le quota de
     * participants se compte sur les copies **engagées** : s'en servir ici
     * fermerait une copie sur sa propre existence.
     */
    public function deadline(): ?Carbon
    {
        $closesAt = $this->form?->close_date;

        if ($this->expires_at === null) {
            return $closesAt;
        }

        if ($closesAt === null) {
            return $this->expires_at;
        }

        return $closesAt->lessThan($this->expires_at) ? $closesAt : $this->expires_at;
    }

    /**
     * L'échéance atteinte est-elle celle de la fermeture de l'évaluation ?
     *
     * Sert au message : « l'épreuve est fermée » n'est pas « votre temps est
     * écoulé ». Le second accuse une montre, le premier constate une échéance
     * commune — et l'étudiant ne lit pas les deux de la même façon.
     */
    public function closedByQuiz(): bool
    {
        $closesAt = $this->form?->close_date;

        return $closesAt !== null
            && ($this->expires_at === null || $closesAt->lessThanOrEqualTo($this->expires_at));
    }

    /**
     * Le temps est-il arrivé à échéance ?
     *
     * Un compte à rebours affiché à zéro ne suffit pas : c'est cette méthode
     * qui décide, côté serveur, à chaque affichage de question comme à la
     * soumission.
     *
     * Une copie jamais commencée n'a pas d'échéance : c'est une référence
     * réservée, pas une épreuve en cours, et la fermer la sortirait de la liste
     * des étudiants qui peuvent encore se présenter.
     *
     * « À l'échéance » vaut « échue », et non « peut-être encore ouverte » : le
     * navigateur rend la copie à l'instant précis où son décompte atteint zéro,
     * et une comparaison stricte ferait alors lire cette remise comme volontaire —
     * aucun message d'échéance, aucun statut de temps écoulé.
     */
    public function hasExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return Carbon::now()->greaterThanOrEqualTo($this->deadline());
    }

    /**
     * Secondes restantes avant l'échéance, jamais négatives.
     *
     * Arrondi **au-dessus** : une seconde entamée n'est pas une seconde
     * écoulée. Cette valeur est celle que le navigateur décompte, et il rend la
     * copie en atteignant zéro : tronquée, elle lui ferait atteindre zéro près
     * d'une seconde avant l'heure, et la remise partirait trop tôt — le serveur
     * la lirait alors comme volontaire, alors que l'échéance n'était pas passée.
     */
    public function remainingSeconds(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, (int) ceil(Carbon::now()->diffInSeconds($this->deadline(), false)));
    }

    /** Les cinq dernières minutes de l'épreuve ont-elles commencé ? */
    public function endsSoon(): bool
    {
        return $this->isInProgress()
            && $this->remainingSeconds() > 0
            && $this->remainingSeconds() <= self::REMINDER_SECONDS;
    }

    /**
     * Le rappel de fin, tel que la page d'épreuve l'annonce.
     *
     * La phrase est écrite ici, et non dans la vue, pour la même raison que le
     * seuil : les deux vont ensemble. Une épreuve qui ferme à une heure fixe ne
     * s'annonce pas comme un chrono qui s'achève — « votre temps est presque
     * écoulé » accuse une montre, alors que la fermeture, elle, est commune à
     * toute la salle et n'appartient à personne.
     */
    public function endsSoonMessage(): string
    {
        $minutes = max(1, (int) round(self::REMINDER_SECONDS / 60));

        return $this->closedByQuiz()
            ? "L'épreuve ferme dans moins de {$minutes} minutes."
            : "Il vous reste moins de {$minutes} minutes.";
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
     * Libellé d'un type d'infraction, tel qu'il se lit dans le bulletin.
     */
    public const INFRACTION_LABELS = [
        'tab_hidden' => 'onglet masqué',
        'window_blur' => 'fenêtre quittée',
        'fullscreen_exit' => 'plein écran quitté',
        'copy_attempt' => 'copie ou clic droit tenté',
    ];

    /**
     * Message lu par le candidat au moment où la sortie est enregistrée.
     */
    private const INFRACTION_MESSAGES = [
        'tab_hidden' => "Sortie d'onglet enregistrée. Restez sur la page de l'évaluation.",
        'window_blur' => 'Fenêtre quittée : sortie enregistrée.',
        'fullscreen_exit' => 'Sortie du plein écran enregistrée.',
        'copy_attempt' => 'Copier-coller ou clic droit bloqué : tentative enregistrée.',
    ];

    /**
     * Deux signaux pour une même sortie ne doivent pas peser double.
     *
     * Le navigateur dédoublonne déjà ; ce délai protège des cas où deux pages
     * vivent en même temps (nouvelle carte, animation d'arrière-plan) ou d'une
     * page rechargée à contretemps.
     */
    public const INFRACTION_DEDUPE_SECONDS = 2;

    public static function infractionLabel(string $type): string
    {
        return self::INFRACTION_LABELS[$type] ?? $type;
    }

    public static function infractionMessage(string $type): string
    {
        return self::INFRACTION_MESSAGES[$type] ?? 'Sortie de fenêtre enregistrée.';
    }

    /**
     * Ajoute une infraction au journal (perte de focus, sortie de plein écran).
     *
     * Retourne faux quand l'entrée est reconnue comme un doublon : rien n'est
     * écrit, et le compteur ne bouge pas. Une trace en double ferait douter du
     * journal entier au moment précis où il sert de preuve.
     */
    public function recordInfraction(string $type, ?string $detail = null): bool
    {
        $journal = $this->infractions ?? [];
        $last = $journal === [] ? null : $journal[array_key_last($journal)];

        if ($last !== null && ($last['type'] ?? null) === $type && isset($last['at'])) {
            $seconds = abs(Carbon::now()->getTimestamp() - Carbon::parse($last['at'])->getTimestamp());

            if ($seconds < self::INFRACTION_DEDUPE_SECONDS) {
                return false;
            }
        }

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

        return true;
    }

    /**
     * Répartition des sorties enregistrées, par nature.
     *
     * Un total indistinct ne dit rien : « 3 » peut vouloir dire trois onglets
     * masqués ou trois sorties de plein écran, ce qui ne raconte pas la même
     * chose devant un conseil de discipline.
     *
     * @return array<string, int>
     */
    public function infractionBreakdown(): array
    {
        $counts = [];

        foreach ($this->infractions ?? [] as $entry) {
            $type = (string) ($entry['type'] ?? 'inconnu');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Résumé lisible : « 2 onglet masqué, 1 plein écran quitté ».
     */
    public function infractionSummary(): string
    {
        $parts = [];

        foreach ($this->infractionBreakdown() as $type => $count) {
            $parts[] = $count.' '.self::infractionLabel($type);
        }

        return $parts === [] ? 'aucune sortie enregistrée' : implode(', ', $parts);
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

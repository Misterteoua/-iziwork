<?php

namespace App\Models;

use App\Support\QuizReference;
use App\Support\ShortCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Un correcteur externe : quelqu'un qui corrige des copies sans jamais avoir de
 * compte d'administration.
 *
 * Son accès tient à deux éléments distincts, et c'est délibéré :
 *   - un **lien personnel** (`link_code`), qui dit seulement où se connecter ;
 *   - une **référence** de dix caractères, transmise avec son email, qui est la
 *     clé.
 *
 * Un lien transféré ne donne donc aucun pouvoir de correction : il faut encore
 * connaître la référence ET l'email. C'est la même logique que la référence
 * d'évaluation d'un étudiant, appliquée à un correcteur.
 *
 * L'échéance n'est jamais appliquée par une tâche planifiée : elle est évaluée à
 * chaque requête (voir isExpired()). Il n'y a donc rien à faire tourner sur le
 * serveur, et l'échéance ne peut pas dériver.
 */
class Grader extends Model
{
    /**
     * Motif d'un code de lien personnel : exactement huit caractères.
     *
     * La longueur exacte est une sécurité en plus d'une contrainte de forme :
     * aucun segment fixe de l'espace des correcteurs (« connexion », « copies »,
     * « export »…) ne fait huit caractères, donc un code ne peut jamais être
     * confondu avec une page de l'espace.
     */
    public const LINK_PATTERN = '[A-Za-z0-9]{'.ShortCode::LENGTH.'}';

    protected $fillable = [
        'name',
        'email',
        'reference',
        'link_code',
        'expires_at',
        'last_seen_at',
        'last_ip',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Un nouveau correcteur, avec sa référence et son lien tirés au sort.
     *
     * Aucune collision n'est laissée au hasard d'une exception de contrainte :
     * on retire tant que la valeur est prise, sur sa propre table.
     */
    public static function createFor(string $name, string $email, ?Carbon $expiresAt, ?int $createdBy): self
    {
        return static::create([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'reference' => QuizReference::generate(
                fn (string $reference): bool => static::where('reference', $reference)->exists()
            ),
            'link_code' => self::freshLinkCode(),
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);
    }

    /** Les évaluations que ce correcteur a le droit de corriger. */
    public function forms()
    {
        return $this->belongsToMany(Form::class, 'grader_assignments')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    public function assignments()
    {
        return $this->hasMany(GraderAssignment::class);
    }

    /**
     * Les réponses dont il est l'auteur de la note ou du commentaire.
     *
     * C'est ce que la trace `graded_by_grader_id` permet et que rien d'autre ne
     * permettait : savoir de qui vient une note, et donc ne lui montrer dans son
     * export que ce qu'il a effectivement corrigé.
     */
    public function gradedAnswers()
    {
        return $this->hasMany(QuizAnswer::class, 'graded_by_grader_id');
    }

    /** L'adresse personnelle à transmettre (et à imprimer en QR code). */
    public function link(): string
    {
        return url('/correction/'.$this->link_code);
    }

    /**
     * Un nouveau code de lien : l'ancien cesse aussitôt de fonctionner.
     *
     * Le QR code n'a pas à être régénéré à la main : il est calculé à partir de
     * ce lien à chaque affichage, jamais stocké. Un QR périmé est donc
     * impossible.
     */
    public function rotateLink(): void
    {
        $this->update(['link_code' => self::freshLinkCode()]);
    }

    /**
     * L'échéance est-elle dépassée ?
     *
     * Une mission sans échéance ne se ferme pas : c'est ce qui distingue
     * « délai de trois jours » de « pas de délai ».
     *
     * Le test est « pas dans le futur », et non « strictement passé » : une
     * suspension pose l'échéance à l'instant présent, et l'accès doit se fermer
     * dans la seconde, pas à la seconde suivante.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    /** Jours restants avant l'échéance, négatifs si elle est dépassée. */
    public function daysRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) ceil(Carbon::now()->diffInHours($this->expires_at, false) / 24);
    }

    /** Trace de passage : quand, et depuis quelle adresse. */
    public function touchActivity(Request $request): void
    {
        $this->forceFill([
            'last_seen_at' => Carbon::now(),
            'last_ip' => $request->ip(),
        ])->save();
    }

    private static function freshLinkCode(): string
    {
        for ($try = 0; $try < 10; $try++) {
            $code = ShortCode::generate();

            if (! static::where('link_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Aucun code de lien correcteur disponible.');
    }
}

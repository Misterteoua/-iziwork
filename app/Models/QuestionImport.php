<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un import de questions en attente de relecture.
 *
 * L'aperçu n'est plus une étape de passage : le jeu analysé vit ici, entre
 * l'analyse du fichier et la confirmation. Il survit donc à la fermeture du
 * navigateur, à une déconnexion et à un changement de poste, et la reprise se
 * fait par un simple lien — sans retéléverser le fichier.
 *
 * Les questions sont stockées en texte et relues en tableau : le jeu est celui
 * de la relecture, retouches comprises, et non le contenu d'origine du fichier.
 */
class QuestionImport extends Model
{
    /** Durée de vie d'un aperçu : au-delà, le fichier a probablement changé. */
    public const LIFETIME_DAYS = 7;

    protected $fillable = [
        'form_id',
        'admin_user_id',
        'questions',
        'errors',
        'ignored',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'errors' => 'array',
            'ignored' => 'integer',
        ];
    }

    /** L'évaluation dans laquelle les questions seront ajoutées. */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    /** L'enseignant qui a lancé l'import : lui seul peut le reprendre. */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /**
     * Le jeu de questions relu, tel qu'il sera écrit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function questionSet(): array
    {
        return is_array($this->questions) ? array_values($this->questions) : [];
    }

    public function questionCount(): int
    {
        return count($this->questionSet());
    }

    /**
     * L'aperçu proposé pour cette évaluation et cet enseignant, s'il existe.
     *
     * Un aperçu expiré n'est pas proposé : il est effacé au passage, sinon la
     * page de l'évaluation annoncerait une reprise qui mène à une page morte.
     */
    public static function pendingFor(Form $quiz, ?int $adminId): ?self
    {
        self::sweep();

        return static::query()
            ->where('form_id', $quiz->getKey())
            ->where('admin_user_id', $adminId)
            ->latest('updated_at')
            ->first();
    }

    /** Un aperçu trop vieux n'est plus repris. */
    public function isExpired(): bool
    {
        return $this->updated_at !== null
            && $this->updated_at->lt(now()->subDays(self::LIFETIME_DAYS));
    }

    /** Efface les aperçus abandonnés depuis plus d'une semaine. */
    public static function sweep(): int
    {
        return static::query()
            ->where('updated_at', '<', now()->subDays(self::LIFETIME_DAYS))
            ->delete();
    }
}

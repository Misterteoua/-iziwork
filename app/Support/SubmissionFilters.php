<?php

namespace App\Support;

use App\Models\Form;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Filtres de soumissions lus depuis l'URL.
 *
 * Le tableau de bord et la page des soumissions d'un formulaire partagent la
 * même barre de filtres : sans cette classe, la règle « la plage de dates
 * prioritaire, borne de fin inclusive » existerait en double et finirait par
 * diverger entre les deux pages.
 *
 * Aucune valeur n'est obligatoire et aucune saisie inconnue n'est rejetée :
 * une URL bricolée à la main doit afficher la page non filtrée, pas une
 * erreur.
 */
final class SubmissionFilters
{
    /** Périodes proposées dans la barre de filtres. */
    public const PERIODS = ['today', '7d', '30d', 'month', 'all'];

    /** Statuts acceptés. */
    public const STATUSES = ['pending', 'validated'];

    /** Longueur maximale d'une recherche, par simple prudence. */
    private const MAX_SEARCH_LENGTH = 100;

    /**
     * @param  int|null  $formId  Formulaire sélectionné (le tableau de bord seul
     *                            propose ce filtre : la page d'un formulaire est
     *                            déjà limitée au sien).
     */
    private function __construct(
        public readonly ?int $formId,
        public readonly string $period,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $status,
        public readonly ?string $search,
    ) {}

    /**
     * @param  bool  $withForm  Lire le filtre « formulaire » : inutile quand la
     *                          page est déjà limitée à un formulaire.
     */
    public static function fromRequest(Request $request, bool $withForm = true): self
    {
        return new self(
            formId: $withForm ? self::formId($request->query('form')) : null,
            period: self::period($request->query('period')),
            from: self::date($request->query('from')),
            to: self::date($request->query('to')),
            status: self::status($request->query('status')),
            search: self::search($request->query('search')),
        );
    }

    /**
     * Au moins un filtre est-il réellement appliqué ?
     *
     * « period=all » et un statut vide ne filtrent rien : le bouton
     * « Réinitialiser » ne doit donc pas apparaître pour eux.
     */
    public function isActive(): bool
    {
        return $this->formId !== null
            || $this->status !== null
            || $this->period !== 'all'
            || $this->from !== null
            || $this->to !== null
            || $this->search !== null;
    }

    /**
     * Bornes de date à appliquer à `created_at`, bornes incluses.
     *
     * Une plage personnalisée a toujours la priorité sur la période préréglée :
     * si les deux sont présents, c'est l'utilisateur qui a rempli les champs de
     * dates qui est écouté. `endOfDay()` est inclusif volontairement — sans lui,
     * une soumission déposée à 23 h 59 le dernier jour de la plage
     * disparaîtrait.
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public function bounds(): array
    {
        if ($this->from !== null || $this->to !== null) {
            return [
                $this->from !== null ? Carbon::parse($this->from)->startOfDay() : null,
                $this->to !== null ? Carbon::parse($this->to)->endOfDay() : null,
            ];
        }

        $now = Carbon::now();

        return match ($this->period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [null, null],
        };
    }

    /**
     * Applique les filtres à une requête de soumissions.
     *
     * Point unique d'application : la liste du tableau de bord, la page d'un
     * formulaire, l'export CSV et le ZIP de téléchargement passent tous par ici,
     * donc aucun d'eux ne peut filtrer « presque » comme les autres.
     */
    public function apply(Builder $query): Builder
    {
        [$start, $end] = $this->bounds();

        return $query
            ->when($this->formId, fn (Builder $q, int $id) => $q->where('form_id', $id))
            ->when($this->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($start, fn (Builder $q) => $q->where('created_at', '>=', $start))
            ->when($end, fn (Builder $q) => $q->where('created_at', '<=', $end))
            ->when($this->search, fn (Builder $q, string $term) => $this->applySearch($q, $term));
    }

    /**
     * Recherche « nom ou email », insensible à la casse quand la collation de
     * la base le permet (MySQL en utf8mb4_*_ci et SQLite sur l'ASCII).
     *
     * Le code anonyme est inclus dans la recherche : sur un formulaire anonyme,
     * c'est l'identifiant affiché à la place du nom, et un administrateur qui
     * chercherait un code sans le trouver croirait à une panne.
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $pattern = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pattern): void {
            $q->where('student_name', 'like', $pattern)
                ->orWhere('student_email', 'like', $pattern)
                ->orWhere('anonymous_code', 'like', $pattern);
        });
    }

    /**
     * Un formulaire inconnu (identifiant supprimé, valeur non numérique) est
     * traité comme « tous les formulaires » : le filtre disparaît au lieu de
     * vider la page ou de lever une erreur.
     */
    private static function formId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        $id = (int) $value;

        return Form::whereKey($id)->exists() ? $id : null;
    }

    /**
     * Terme de recherche, nettoyé.
     *
     * Les jokers SQL (`%`, `_`) et l'antislash sont retirés : sans cela, une
     * recherche « % » listerait tout le monde, et « _ » ferait correspondre
     * n'importe quel caractère. Un nom d'étudiant n'en a jamais besoin.
     */
    private static function search(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $term = trim(str_replace(['%', '_', '\\'], '', (string) $value));

        if ($term === '') {
            return null;
        }

        return Str::limit($term, self::MAX_SEARCH_LENGTH, '');
    }

    private static function period(mixed $value): string
    {
        return is_scalar($value) && in_array((string) $value, self::PERIODS, true)
            ? (string) $value
            : 'all';
    }

    private static function status(mixed $value): ?string
    {
        return is_scalar($value) && in_array((string) $value, self::STATUSES, true)
            ? (string) $value
            : null;
    }

    /**
     * N'accepte qu'une date stricte AAAA-MM-JJ réellement existante (le
     * 31 février est refusé). Toute autre saisie est ignorée.
     */
    private static function date(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        [, $year, $month, $day] = $matches;

        return checkdate((int) $month, (int) $day, (int) $year) ? $value : null;
    }
}

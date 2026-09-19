<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Filtres de la liste des évaluations : recherche, période de création, état, tri.
 *
 * Même principe que {@see SubmissionFilters} pour les soumissions : lecture
 * tolérante des paramètres d'URL, application en un seul endroit, et sortie
 * brute — directement lisible — pour la vue. Un paramètre invalide est ignoré
 * plutôt que refusé : une URL bricolée à la main affiche la liste complète au
 * lieu d'une erreur.
 */
final class QuizFilters
{
    /** Horizons proposés, appliqués à la date de création de l'évaluation. */
    public const PERIODS = ['today', '7d', '30d', 'month', 'all'];

    /** États possibles d'une évaluation, ceux de la colonne `status`. */
    public const STATUSES = ['active', 'inactive'];

    /** `recent` est le tri historique de la page : le plus récent d'abord. */
    public const SORTS = ['recent', 'oldest', 'title'];

    /** Au-delà, ce n'est plus une recherche mais une requête déguisée. */
    private const MAX_SEARCH_LENGTH = 100;

    private function __construct(
        public readonly string $period,
        public readonly ?string $status,
        public readonly ?string $search,
        public readonly string $sort,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            period: self::period($request->input('period')),
            status: self::status($request->input('status')),
            search: self::search($request->input('search')),
            sort: self::sort($request->input('sort')),
        );
    }

    /**
     * Un filtre est-il actif ?
     *
     * Le tri n'en fait pas partie : changer l'ordre d'affichage n'est pas
     * restreindre la liste. Sinon, la simple consultation des évaluations les
     * plus anciennes afficherait un bouton « Réinitialiser » sans raison.
     */
    public function isActive(): bool
    {
        return $this->period !== 'all' || $this->status !== null || $this->search !== null;
    }

    /**
     * Bornes de la période, sur la date de création.
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public function bounds(): array
    {
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
     * Applique les filtres à une requête d'évaluations.
     *
     * Point unique d'application : il n'existe pas de second endroit qui
     * « filtrerait presque pareil », donc l'en-tête de comptage et la grille ne
     * peuvent pas se contredire.
     */
    public function apply(Builder $query): Builder
    {
        [$start, $end] = $this->bounds();

        $query
            ->when($this->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($start, fn (Builder $q) => $q->where('created_at', '>=', $start))
            ->when($end, fn (Builder $q) => $q->where('created_at', '<=', $end))
            ->when($this->search, fn (Builder $q, string $term) => $this->applySearch($q, $term));

        return match ($this->sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'title' => $query->orderBy('title')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * Recherche sur le titre et les consignes.
     *
     * La description est incluse parce qu'un enseignant y met souvent le nom de
     * la matière ou de la session : refuser de la chercher l'obligerait à
     * parcourir la grille à l'oeil.
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $pattern = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pattern): void {
            $q->where('title', 'like', $pattern)
                ->orWhere('description', 'like', $pattern);
        });
    }

    /**
     * Terme de recherche nettoyé.
     *
     * Les jokers SQL (`%`, `_`) et l'antislash sont retirés : sans ce nettoyage,
     * une recherche « % » listerait tout et « _ » ferait correspondre n'importe
     * quel caractère. Aucun titre d'évaluation n'en a besoin.
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

    private static function sort(mixed $value): string
    {
        return is_scalar($value) && in_array((string) $value, self::SORTS, true)
            ? (string) $value
            : 'recent';
    }
}

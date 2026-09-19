<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** Nombre de soumissions affichées quand aucun filtre n'est actif. */
    private const DEFAULT_LIMIT = 10;

    /** Nombre de soumissions affichées dès qu'un filtre est actif. */
    private const FILTERED_LIMIT = 25;

    /** Périodes proposées dans la barre de filtres. */
    public const PERIODS = ['today', '7d', '30d', 'month', 'all'];

    /**
     * Tableau de bord, éventuellement filtré.
     *
     * Les filtres vivent dans l'URL (form, period, from, to, status) : ils sont
     * donc rechargeables et partageables par simple lien. Aucun paramètre n'est
     * considéré comme obligatoire, et toute valeur inconnue ou malformée est
     * ignorée silencieusement : une URL bricolée à la main ne doit jamais
     * provoquer d'erreur, juste une page non filtrée.
     */
    public function index(Request $request)
    {
        $filters = $this->readFilters($request);

        [$start, $end] = $this->dateBounds($filters);

        $query = Submission::with('form')
            ->when($filters['form_id'], fn ($q, $id) => $q->where('form_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end));

        $filteredCount = (clone $query)->count();

        $isFiltered = $filters['form_id'] !== null
            || $filters['status'] !== null
            || $filters['period'] !== 'all'
            || $filters['from'] !== null
            || $filters['to'] !== null;

        $stats = [
            'total_forms' => Form::count(),
            'active_forms' => Form::where('status', 'active')->count(),
            'total_submissions' => Submission::count(),
            'recent_submissions' => $query
                ->orderByDesc('created_at')
                ->limit($isFiltered ? self::FILTERED_LIMIT : self::DEFAULT_LIMIT)
                ->get(),
        ];

        return view('admin.dashboard', [
            'stats' => $stats,
            'forms' => Form::orderBy('title')->get(['id', 'title']),
            'filters' => $filters,
            'isFiltered' => $isFiltered,
            'filteredCount' => $filteredCount,
        ]);
    }

    /**
     * Lit les filtres de l'URL en ignorant tout ce qui n'est pas reconnu.
     *
     * @return array{form_id: int|null, period: string, from: string|null, to: string|null, status: string|null}
     */
    private function readFilters(Request $request): array
    {
        return [
            'form_id' => $this->readFormId($request->query('form')),
            'period' => in_array($request->query('period'), self::PERIODS, true)
                ? (string) $request->query('period')
                : 'all',
            'from' => $this->readDate($request->query('from')),
            'to' => $this->readDate($request->query('to')),
            'status' => in_array($request->query('status'), ['pending', 'validated'], true)
                ? (string) $request->query('status')
                : null,
        ];
    }

    /**
     * Un formulaire inconnu (identifiant supprimé, valeur non numérique) est
     * traité comme « tous les formulaires » : le filtre disparaît au lieu de
     * vider la page ou de lever une erreur.
     */
    private function readFormId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        $id = (int) $value;

        return Form::whereKey($id)->exists() ? $id : null;
    }

    /**
     * N'accepte qu'une date stricte AAAA-MM-JJ réellement existante
     * (le 31 février est refusé). Toute autre saisie est ignorée.
     */
    private function readDate(mixed $value): ?string
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

    /**
     * Traduit les filtres de date en bornes de requête, bornes incluses.
     *
     * Une plage personnalisée (from/to) a toujours la priorité sur la période
     * préréglée : si les deux sont fournis, c'est l'utilisateur qui a cliqué
     * dans les champs de dates qui est écouté. `endOfDay()` inclusif est
     * volontaire — sans lui, une soumission déposée à 23 h 59 le dernier jour
     * de la plage disparaîtrait du tableau.
     *
     * @param  array{period: string, from: string|null, to: string|null}  $filters
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function dateBounds(array $filters): array
    {
        if ($filters['from'] !== null || $filters['to'] !== null) {
            return [
                $filters['from'] !== null ? Carbon::parse($filters['from'])->startOfDay() : null,
                $filters['to'] !== null ? Carbon::parse($filters['to'])->endOfDay() : null,
            ];
        }

        $now = Carbon::now();

        return match ($filters['period']) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [null, null],
        };
    }
}

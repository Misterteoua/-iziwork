<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use App\Support\SubmissionFilters;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Nombre de soumissions affichées quand aucun filtre n'est actif. */
    private const DEFAULT_LIMIT = 10;

    /** Nombre de soumissions affichées dès qu'un filtre est actif. */
    private const FILTERED_LIMIT = 25;

    /**
     * Tableau de bord, éventuellement filtré.
     *
     * Les filtres vivent dans l'URL (form, period, from, to, status) : ils sont
     * donc rechargeables et partageables par simple lien. La lecture de ces
     * paramètres est partagée avec la page des soumissions d'un formulaire,
     * via SubmissionFilters.
     */
    public function index(Request $request)
    {
        $filters = SubmissionFilters::fromRequest($request);

        [$start, $end] = $filters->bounds();

        $query = Submission::with('form')
            ->when($filters->formId, fn ($q, $id) => $q->where('form_id', $id))
            ->when($filters->status, fn ($q, $status) => $q->where('status', $status))
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end));

        $isFiltered = $filters->isActive();

        // Compté avant l'ajout de la limite, qui s'applique au même objet.
        $filteredCount = (clone $query)->count();

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
            'filters' => $this->viewFilters($filters),
            'isFiltered' => $isFiltered,
            'filteredCount' => $filteredCount,
        ]);
    }

    /**
     * Représentation des filtres attendue par la vue (tableau indexé), pour ne
     * pas la coupler aux propriétés de l'objet partagé.
     *
     * @return array{form_id: int|null, period: string, from: string|null, to: string|null, status: string|null}
     */
    private function viewFilters(SubmissionFilters $filters): array
    {
        return [
            'form_id' => $filters->formId,
            'period' => $filters->period,
            'from' => $filters->from,
            'to' => $filters->to,
            'status' => $filters->status,
        ];
    }
}

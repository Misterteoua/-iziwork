<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use App\Support\SubmissionFilters;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    /** Nombre de soumissions affichées quand aucun filtre n'est actif. */
    private const DEFAULT_LIMIT = 10;

    /** Nombre de soumissions affichées dès qu'un filtre est actif. */
    private const FILTERED_LIMIT = 25;

    /**
     * Séparateur du CSV.
     *
     * Un point-virgule, et non la virgule de la RFC 4180 : Excel en français
     * utilise la virgule comme séparateur décimal et n'éclate donc pas un CSV
     * séparé par des virgules — tout atterrit dans une seule colonne.
     */
    private const CSV_SEPARATOR = ';';

    /** Lignes écrites par lots, pour ne pas charger tout l'export en mémoire. */
    private const CSV_CHUNK = 200;

    /** Colonnes de l'export, dans l'ordre. */
    private const CSV_COLUMNS = [
        'Référence',
        'Formulaire',
        'Nom',
        'Email',
        'Téléphone',
        'Filière',
        'Code anonyme',
        'Statut',
        'Date de soumission',
        'Pièces jointes',
    ];

    /**
     * Tableau de bord, éventuellement filtré.
     *
     * Les filtres vivent dans l'URL (form, period, from, to, status) : ils sont
     * donc rechargeables et partageables par simple lien. La lecture de ces
     * paramètres est partagée avec la page des soumissions d'un formulaire et
     * avec l'export CSV, via SubmissionFilters.
     */
    public function index(Request $request)
    {
        $filters = SubmissionFilters::fromRequest($request);

        $query = $this->filteredQuery($filters);

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
            'exportUrl' => route('admin.export.submissions', $this->exportQuery($filters)),
        ]);
    }

    /**
     * Export CSV des soumissions correspondant aux filtres actifs.
     *
     * Aucune limite d'affichage : ce que voit l'administrateur (10 ou 25 lignes)
     * n'est qu'une fenêtre, l'export porte sur la totalité du résultat filtré.
     * L'adresse IP est volontairement absente : un fichier CSV circule, et elle
     * n'apporte rien au traitement des dépôts.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = SubmissionFilters::fromRequest($request);

        $submissions = $this->filteredQuery($filters)
            ->withCount('files')
            ->orderByDesc('created_at');

        $filename = 'soumissions_'.now()->format('Y-m-d_Hi').'.csv';

        return response()->streamDownload(function () use ($submissions): void {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel affiche « Ã‰tudiant » au lieu de
            // « Étudiant ».
            fwrite($handle, "\xEF\xBB\xBF");

            $this->writeRow($handle, self::CSV_COLUMNS);

            $submissions->chunk(self::CSV_CHUNK, function ($rows) use ($handle): void {
                foreach ($rows as $submission) {
                    $this->writeRow($handle, [
                        $submission->id,
                        $submission->form->title,
                        $submission->student_name,
                        $submission->student_email,
                        $submission->student_phone,
                        $submission->student_major,
                        $submission->anonymous_code,
                        $submission->status === 'validated' ? 'Validée' : 'En attente',
                        $submission->created_at->format('d/m/Y H:i'),
                        $submission->files_count,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Requête des soumissions retenues par les filtres, sans limite ni tri :
     * le tableau de bord y ajoute sa fenêtre, l'export CSV les prend toutes.
     * L'application des filtres elle-même appartient à SubmissionFilters, pour
     * que la liste, l'export et le ZIP ne puissent pas diverger.
     */
    private function filteredQuery(SubmissionFilters $filters): Builder
    {
        return $filters->apply(Submission::with('form'));
    }

    /**
     * Écrit une ligne CSV en neutralisant les formules.
     *
     * Un nom d'étudiant commençant par « = », « + », « - » ou « @ » est
     * interprété comme une formule par Excel et LibreOffice : à l'ouverture du
     * fichier, le tableur l'exécute. Le préfixe d'une apostrophe force le texte.
     *
     * @param  array<int, mixed>  $values
     */
    private function writeRow($handle, array $values): void
    {
        $row = array_map(function ($value): string {
            $value = (string) ($value ?? '');

            return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
        }, $values);

        // Le cinquième argument vide désactive l'échappement maison de PHP,
        // qui produirait des guillemets parasites avec le séparateur choisi.
        fputcsv($handle, $row, self::CSV_SEPARATOR, '"', '');
    }

    /**
     * Filtres à transmettre au lien d'export : uniquement ceux qui filtrent
     * réellement, pour ne pas produire d'URL bruyante en l'absence de filtre.
     *
     * @return array<string, scalar>
     */
    private function exportQuery(SubmissionFilters $filters): array
    {
        return array_filter([
            'form' => $filters->formId,
            'period' => $filters->period !== 'all' ? $filters->period : null,
            'from' => $filters->from,
            'to' => $filters->to,
            'status' => $filters->status,
        ], fn ($value) => $value !== null && $value !== '');
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

<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use App\Models\QuizAttempt;
use App\Support\QuizReference;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Évaluations en ligne, côté administrateur.
 *
 * Volontairement séparé de FormController : le module de dépôt de travaux ne
 * doit pas changer de comportement parce qu'un questionnaire existe. Les deux
 * partagent la table `forms`, distinguée par la colonne `type`.
 */
class QuizController extends Controller
{
    /** Séparateur et encodage du CSV, comme pour l'export des soumissions. */
    private const CSV_SEPARATOR = ';';

    private const CSV_COLUMNS = [
        'Référence',
        'Nom',
        'Email',
        'Filière',
        'Statut',
        'Note',
        'Barème',
        'Temps (min)',
        'Infractions',
        'Commencée le',
        'Terminée le',
    ];

    public function index()
    {
        $quizzes = Form::where('type', Form::TYPE_QUIZ)
            ->withCount([
                'fields as questions_count' => fn ($query) => $query->whereIn('field_type', FormField::QUESTION_TYPES),
                'attempts',
                'attempts as submitted_count' => fn ($query) => $query->whereIn(
                    'status',
                    [QuizAttempt::STATUS_SUBMITTED, QuizAttempt::STATUS_EXPIRED]
                ),
            ])
            ->orderByDesc('created_at')
            ->get();

        return view('admin.quizzes.index', compact('quizzes'));
    }

    public function create()
    {
        return view('admin.quizzes.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateSettings($request);

        $quiz = Form::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'token' => Str::random(32),
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'status' => 'inactive',
            'type' => Form::TYPE_QUIZ,
            'quiz_settings' => [
                'duration_minutes' => (int) $validated['duration_minutes'],
                'show_score' => $request->boolean('show_score'),
                'proctoring' => $request->boolean('proctoring'),
            ],
            'created_by' => $request->session()->get('admin_user.id'),
        ]);

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', 'Évaluation créée. Ajoutez maintenant vos questions.');
    }

    public function show(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $quiz->load('fields');
        $questions = $quiz->quizQuestions()->get();
        $attempts = $quiz->attempts()->orderByDesc('created_at')->get();

        return view('admin.quizzes.show', compact('quiz', 'questions', 'attempts'));
    }

    public function updateSettings(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $validated = $this->validateSettings($request);

        $quiz->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $request->boolean('is_anonymous'),
            'quiz_settings' => [
                'duration_minutes' => (int) $validated['duration_minutes'],
                'show_score' => $request->boolean('show_score'),
                'proctoring' => $request->boolean('proctoring'),
            ],
        ]);

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', 'Réglages enregistrés.');
    }

    public function toggle(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $quiz->update(['status' => $quiz->status === 'active' ? 'inactive' : 'active']);

        return redirect()->route('admin.quizzes.show', $quiz)
            ->with('success', $quiz->status === 'active'
                ? 'Évaluation ouverte aux étudiants.'
                : 'Évaluation fermée.');
    }

    public function destroy(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $quiz->delete();

        return redirect()->route('admin.quizzes.index')
            ->with('success', 'Évaluation supprimée.');
    }

    // ------------------------------------------------------------- Questions

    public function storeQuestion(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attributes = $this->validatedQuestion($request);

        $quiz->fields()->create([
            'field_label' => trim((string) $request->input('field_label')),
            'field_type' => $attributes['field_type'],
            'required' => true,
            'order' => (int) $quiz->quizQuestions()->max('order') + 1,
            'options' => $attributes['options'],
            'correct_answer' => $attributes['correct'],
            'points' => $attributes['points'],
        ]);

        return back()->with('success', 'Question ajoutée.');
    }

    public function updateQuestion(Request $request, Form $quiz, FormField $field)
    {
        $this->assertQuiz($quiz);
        $this->assertQuestion($quiz, $field);

        $attributes = $this->validatedQuestion($request);

        $field->update([
            'field_label' => trim((string) $request->input('field_label')),
            'field_type' => $attributes['field_type'],
            'options' => $attributes['options'],
            'correct_answer' => $attributes['correct'],
            'points' => $attributes['points'],
        ]);

        return back()->with('success', 'Question mise à jour.');
    }

    public function destroyQuestion(Form $quiz, FormField $field)
    {
        $this->assertQuiz($quiz);
        $this->assertQuestion($quiz, $field);

        $field->delete();

        return back()->with('success', 'Question supprimée.');
    }

    // ------------------------------------------------------------ Références

    /**
     * Prépare des références d'anonymat.
     *
     * Dès qu'une seule référence existe, l'évaluation passe en mode « liste » :
     * l'étudiant devra saisir la sienne, et une référence inconnue est refusée.
     * C'est ce qui permet de distribuer les références avant l'épreuve.
     */
    public function generateReferences(Request $request, Form $quiz)
    {
        $this->assertQuiz($quiz);

        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:500'],
        ], [
            'count.required' => 'Indiquez le nombre de références à générer.',
            'count.integer' => 'Le nombre de références doit être un nombre entier.',
            'count.min' => 'Générez au moins une référence.',
            'count.max' => 'Cinq cents références au maximum en une fois.',
        ]);

        for ($i = 0; $i < (int) $validated['count']; $i++) {
            $quiz->attempts()->create(['reference' => QuizReference::generate()]);
        }

        return back()->with('success', $validated['count'].' référence(s) générée(s).');
    }

    public function resetAttempt(Form $quiz, QuizAttempt $attempt)
    {
        $this->assertQuiz($quiz);
        $this->assertAttempt($quiz, $attempt);

        $attempt->answers()->delete();

        $attempt->update([
            'status' => QuizAttempt::STATUS_PENDING,
            'started_at' => null,
            'expires_at' => null,
            'submitted_at' => null,
            'score' => null,
            'max_score' => null,
            'infractions' => [],
            'infraction_count' => 0,
        ]);

        return back()->with('success', 'Participation réinitialisée : l\'étudiant peut repasser l\'évaluation.');
    }

    // --------------------------------------------------------------- Résultats

    public function results(Form $quiz)
    {
        $this->assertQuiz($quiz);

        $attempts = $quiz->attempts()
            ->withCount('answers')
            ->orderByDesc('submitted_at')
            ->orderBy('reference')
            ->get();

        return view('admin.quizzes.results', compact('quiz', 'attempts'));
    }

    /**
     * Export CSV des résultats, dans le même esprit que l'export des
     * soumissions : BOM pour Excel, point-virgule, formules neutralisées.
     */
    public function exportResults(Form $quiz): StreamedResponse
    {
        $this->assertQuiz($quiz);

        $attempts = $quiz->attempts()->orderByDesc('submitted_at')->orderBy('reference');

        $filename = 'evaluation_'.$quiz->id.'_'.now()->format('Y-m-d_Hi').'.csv';

        $anonymous = $quiz->is_anonymous;

        return response()->streamDownload(function () use ($attempts, $anonymous): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            $this->writeCsvRow($handle, self::CSV_COLUMNS);

            $attempts->chunk(200, function ($rows) use ($handle, $anonymous): void {
                foreach ($rows as $attempt) {
                    // Évaluation anonyme : le nom ne doit pas se retrouver dans
                    // un fichier qui circule, c'est tout l'objet de la référence.
                    $this->writeCsvRow($handle, [
                        $attempt->reference,
                        $anonymous ? null : $attempt->student_name,
                        $attempt->student_email,
                        $attempt->student_major,
                        $this->statusLabel($attempt->status),
                        $attempt->score === null ? null : number_format((float) $attempt->score, 2, ',', ''),
                        $attempt->max_score === null ? null : number_format((float) $attempt->max_score, 2, ',', ''),
                        $attempt->started_at === null ? null : round($attempt->elapsedSeconds() / 60, 1),
                        $attempt->infraction_count,
                        $attempt->started_at?->format('d/m/Y H:i'),
                        $attempt->submitted_at?->format('d/m/Y H:i'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- Interne

    /**
     * @return array<string, mixed>
     */
    private function validateSettings(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'max_submissions' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'open_date' => ['nullable', 'date'],
            'close_date' => ['nullable', 'date', 'after_or_equal:open_date'],
        ], [
            'title.required' => 'Le titre de l\'évaluation est obligatoire.',
            'duration_minutes.required' => 'La durée de l\'épreuve est obligatoire.',
            'duration_minutes.min' => 'La durée doit être d\'au moins une minute.',
            'duration_minutes.max' => 'La durée ne peut pas dépasser 600 minutes (10 heures).',
            'max_submissions.integer' => 'Le quota de participants doit être un nombre entier.',
            'close_date.after_or_equal' => 'La date de fermeture doit suivre la date d\'ouverture.',
        ]);
    }

    /**
     * Valide une question et remet ses options à plat.
     *
     * Les propositions vides sont retirées (l'administrateur qui n'utilise que
     * trois options laisse la quatrième vide) et les index des bonnes réponses
     * sont recalculés en conséquence — sans ce remappage, désigner la bonne
     * réponse décalerait dès qu'une option vide traîne au milieu.
     *
     * @return array{field_type: string, options: array<int, string>, correct: array<int, int>, points: float}
     */
    private function validatedQuestion(Request $request): array
    {
        $request->validate([
            'field_label' => ['required', 'string', 'max:2000'],
            'field_type' => ['required', Rule::in(FormField::QUESTION_TYPES)],
            'options' => ['required', 'array', 'min:2', 'max:8'],
            'options.*' => ['nullable', 'string', 'max:500'],
            'correct' => ['required', 'array'],
            'correct.*' => ['integer'],
            'points' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ], [
            'field_label.required' => 'L\'énoncé de la question est obligatoire.',
            'field_type.required' => 'Choisissez le type de question.',
            'field_type.in' => 'Le type de question sélectionné est invalide.',
            'options.required' => 'Proposez au moins deux réponses possibles.',
            'options.min' => 'Une question demande au moins deux propositions.',
            'options.max' => 'Huit propositions au maximum par question.',
            'correct.required' => 'Désignez la bonne réponse.',
            'points.required' => 'Indiquez le barème de la question.',
            'points.min' => 'Le barème doit être d\'au moins 0,5 point.',
        ]);

        $options = [];
        $remap = [];

        foreach ((array) $request->input('options') as $index => $label) {
            $label = trim((string) $label);

            if ($label === '') {
                continue;
            }

            $remap[(int) $index] = count($options);
            $options[] = $label;
        }

        if (count($options) < 2) {
            throw ValidationException::withMessages([
                'options' => 'Une question demande au moins deux propositions non vides.',
            ]);
        }

        $correct = [];

        foreach ((array) $request->input('correct') as $index) {
            if (isset($remap[(int) $index])) {
                $correct[] = $remap[(int) $index];
            }
        }

        $correct = array_values(array_unique($correct));
        sort($correct);

        if ($correct === []) {
            throw ValidationException::withMessages([
                'correct' => 'Désignez la bonne réponse parmi les propositions non vides.',
            ]);
        }

        if ($request->input('field_type') === 'radio' && count($correct) > 1) {
            throw ValidationException::withMessages([
                'correct' => 'Une question à choix unique ne peut avoir qu\'une seule bonne réponse.',
            ]);
        }

        return [
            'field_type' => (string) $request->input('field_type'),
            'options' => $options,
            'correct' => $correct,
            'points' => round((float) $request->input('points'), 2),
        ];
    }

    private function writeCsvRow($handle, array $values): void
    {
        $row = array_map(function ($value): string {
            $value = (string) ($value ?? '');

            // Neutralise les formules : un nom saisi par un étudiant ne doit pas
            // être exécuté par le tableur qui ouvre ce fichier.
            return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
        }, $values);

        fputcsv($handle, $row, self::CSV_SEPARATOR, '"', '');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            QuizAttempt::STATUS_SUBMITTED => 'Terminée',
            QuizAttempt::STATUS_EXPIRED => 'Temps écoulé',
            QuizAttempt::STATUS_IN_PROGRESS => 'En cours',
            default => 'Non commencée',
        };
    }

    private function assertQuiz(Form $quiz): void
    {
        abort_unless($quiz->isQuiz(), 404);
    }

    private function assertQuestion(Form $quiz, FormField $field): void
    {
        abort_unless((int) $field->form_id === (int) $quiz->id && $field->isQuestion(), 404);
    }

    private function assertAttempt(Form $quiz, QuizAttempt $attempt): void
    {
        abort_unless((int) $attempt->form_id === (int) $quiz->id, 404);
    }
}

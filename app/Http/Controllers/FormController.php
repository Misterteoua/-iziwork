<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFormRequest;
use App\Http\Requests\UpdateFormRequest;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Support\Str;

class FormController extends Controller
{
    public function index()
    {
        $forms = Form::withCount('submissions')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.forms.index', compact('forms'));
    }

    public function create()
    {
        return view('admin.forms.create');
    }

    public function store(StoreFormRequest $request)
    {
        $validated = $request->validated();
        $form = Form::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'token' => Str::random(32),
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $validated['is_anonymous'] ?? false,
            'status' => 'inactive',
            'created_by' => $request->session()->get('admin_user.id'),
        ]);

        $this->replaceFields($form, $validated);

        return redirect()->route('admin.forms.show', $form)
            ->with('success', 'Formulaire créé avec succès !');
    }

    public function show(Form $form)
    {
        $form->load(['fields', 'submissions.files']);

        return view('admin.forms.show', compact('form'));
    }

    public function edit(Form $form)
    {
        $form->load('fields');

        return view('admin.forms.edit', compact('form'));
    }

    public function update(UpdateFormRequest $request, Form $form)
    {
        $validated = $request->validated();
        $form->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $validated['is_anonymous'] ?? false,
        ]);

        // Keep the existing behavior: editing a form replaces its field list.
        $form->fields()->delete();
        $this->replaceFields($form, $validated);

        return redirect()->route('admin.forms.show', $form)
            ->with('success', 'Formulaire mis à jour avec succès !');
    }

    public function toggleStatus(Form $form)
    {
        $form->update([
            'status' => $form->status === 'active' ? 'inactive' : 'active',
        ]);

        $status = $form->status === 'active' ? 'activé' : 'désactivé';

        return redirect()->route('admin.forms.show', $form)
            ->with('success', "Formulaire {$status} avec succès !");
    }

    public function destroy(Form $form)
    {
        $form->delete();

        return redirect()->route('admin.forms.index')
            ->with('success', 'Formulaire supprimé avec succès !');
    }

    public function copyLink(Form $form)
    {
        return response()->json(['link' => route('submit.form', $form->token)]);
    }

    private function replaceFields(Form $form, array $validated): void
    {
        $labels = $validated['field_labels'];
        $types = $validated['field_types'];
        $requiredIndexes = array_map('intval', $validated['field_requireds'] ?? []);
        $rawOptions = $validated['field_options'] ?? [];

        $created = collect();

        foreach ($labels as $index => $label) {
            $fieldType = $types[$index];
            $options = null;

            if ($fieldType === 'select') {
                $options = $this->normalizeOptions($rawOptions[$index] ?? null);
            }

            $created->push(FormField::create([
                'form_id' => $form->id,
                'field_label' => trim($label),
                'field_type' => $fieldType,
                'required' => in_array($index, $requiredIndexes, true),
                'order' => $index,
                'options' => $options,
            ]));
        }

        /*
         * L'email est la clé anti-doublon des soumissions : la table
         * `submissions` l'exige et elle est unique par formulaire. Si aucun
         * champ du formulaire ne fournit cette valeur (pas de champ de type
         * email, ni de champ libellé « Email » — voir FormField::getFieldName),
         * on l'ajoute automatiquement, requis, en fin de formulaire. La vue
         * étudiant affiche le même bloc de secours pour les formulaires
         * créés avant cette règle.
         */
        if (! $created->contains(fn (FormField $field) => $field->getFieldName() === 'student_email')) {
            FormField::create([
                'form_id' => $form->id,
                'field_label' => 'Adresse email',
                'field_type' => 'email',
                'required' => true,
                'order' => ((int) max(array_keys($labels))) + 1,
                'options' => null,
            ]);
        }
    }

    private function normalizeOptions(?string $rawOptions): ?array
    {
        if ($rawOptions === null || trim($rawOptions) === '') {
            return null;
        }

        $options = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/\R/', $rawOptions)),
            static fn (string $option): bool => $option !== ''
        )));

        return $options === [] ? null : array_slice($options, 0, 100);
    }
}

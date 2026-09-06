<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormField;
use Illuminate\Http\Request;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'open_date' => 'nullable|date',
            'close_date' => 'nullable|date|after_or_equal:open_date',
            'max_submissions' => 'nullable|integer|min:1',
            'is_anonymous' => 'boolean',
            'field_labels' => 'required|array|min:1',
            'field_types' => 'required|array|min:1',
            'field_requireds' => 'array',
            'field_options' => 'nullable|array',
        ]);

        $form = Form::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'token' => Str::random(32),
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $validated['is_anonymous'] ?? false,
            'status' => 'inactive',
            'created_by' => session('admin_user.id'),
        ]);

        foreach ($validated['field_labels'] as $index => $label) {
            if (!empty($label)) {
                $fieldType = $validated['field_types'][$index] ?? 'text';
                $options = null;

                // Parse options for select fields
                if ($fieldType === 'select' && !empty($validated['field_options'][$index])) {
                    $rawOptions = $validated['field_options'][$index];
                    if (is_string($rawOptions)) {
                        $options = array_map('trim', explode("\n", $rawOptions));
                        $options = array_filter($options); // remove empty lines
                        $options = array_values($options);
                    } elseif (is_array($rawOptions)) {
                        $options = array_values(array_filter(array_map('trim', $rawOptions)));
                    }
                }

                FormField::create([
                    'form_id' => $form->id,
                    'field_label' => $label,
                    'field_type' => $fieldType,
                    'required' => in_array($index, $validated['field_requireds'] ?? []),
                    'order' => $index,
                    'options' => $options,
                ]);
            }
        }

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

    public function update(Request $request, Form $form)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'open_date' => 'nullable|date',
            'close_date' => 'nullable|date|after_or_equal:open_date',
            'max_submissions' => 'nullable|integer|min:1',
            'is_anonymous' => 'boolean',
            'field_labels' => 'required|array|min:1',
            'field_types' => 'required|array|min:1',
            'field_requireds' => 'array',
            'field_options' => 'nullable|array',
        ]);

        $form->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'open_date' => $validated['open_date'] ?? null,
            'close_date' => $validated['close_date'] ?? null,
            'max_submissions' => $validated['max_submissions'] ?? null,
            'is_anonymous' => $validated['is_anonymous'] ?? false,
        ]);

        // Update fields
        $form->fields()->delete();
        
        foreach ($validated['field_labels'] as $index => $label) {
            if (!empty($label)) {
                $fieldType = $validated['field_types'][$index] ?? 'text';
                $options = null;

                // Parse options for select fields
                if ($fieldType === 'select' && !empty($validated['field_options'][$index])) {
                    $rawOptions = $validated['field_options'][$index];
                    if (is_string($rawOptions)) {
                        $options = array_map('trim', explode("\n", $rawOptions));
                        $options = array_filter($options);
                        $options = array_values($options);
                    } elseif (is_array($rawOptions)) {
                        $options = array_values(array_filter(array_map('trim', $rawOptions)));
                    }
                }

                FormField::create([
                    'form_id' => $form->id,
                    'field_label' => $label,
                    'field_type' => $fieldType,
                    'required' => in_array($index, $validated['field_requireds'] ?? []),
                    'order' => $index,
                    'options' => $options,
                ]);
            }
        }

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
        $link = route('submit.form', $form->token);
        
        return response()->json(['link' => $link]);
    }
}

{{--
    Formulaire d'une question, partagé entre l'ajout et la modification.

    Variables attendues :
      $action    URL de soumission
      $method    POST ou PUT
      $question  FormField existante, ou null pour une nouvelle question
      $slots     nombre d'emplacements de propositions affichés
--}}
@php
    $slots = $slots ?? 4;
    $existingOptions = $question?->getOptionsList() ?? [];
    $correct = $question?->correctIndexes() ?? [];
@endphp

<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1.5">Énoncé <span class="text-red-500" aria-hidden="true">*</span></label>
        <textarea name="field_label" rows="2" required
                  placeholder="Ex : Quelle est la complexité de la recherche dichotomique ?"
                  class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('field_label', $question?->field_label) }}</textarea>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Type de question</label>
            <select name="field_type"
                    class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                <option value="radio" @selected(old('field_type', $question?->field_type ?? 'radio') === 'radio')>Choix unique</option>
                <option value="checkbox" @selected(old('field_type', $question?->field_type) === 'checkbox')>Choix multiple</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Barème (points)</label>
            <input type="number" name="points" step="0.5" min="0.5" max="100" required
                   value="{{ old('points', $question?->points ?? 1) }}"
                   class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
        </div>
    </div>

    <fieldset>
        <legend class="text-sm font-medium text-slate-700 mb-1.5">Propositions — cochez la ou les bonnes réponses <span class="text-red-500" aria-hidden="true">*</span></legend>

        <div class="space-y-2">
            @for($i = 0; $i < $slots; $i++)
            <div class="flex items-center gap-3">
                <input type="checkbox" name="correct[]" value="{{ $i }}"
                       aria-label="Bonne réponse {{ chr(65 + $i) }}"
                       @checked(in_array($i, array_map('intval', old('correct', $correct)), true))
                       class="h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40 shrink-0">
                <input type="text" name="options[]" value="{{ old('options.'.$i, $existingOptions[$i] ?? '') }}"
                       aria-label="Proposition {{ chr(65 + $i) }}"
                       placeholder="Proposition {{ chr(65 + $i) }}{{ $i === 0 || $i === 1 ? ' (obligatoire)' : '' }}"
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
            </div>
            @endfor
        </div>

        <p class="mt-2 text-xs text-slate-500">
            Laissez vides les propositions inutilisées. Une question à choix unique ne doit avoir qu'une seule bonne réponse.
        </p>
    </fieldset>

    @if($errors->any())
    <div class="rounded-xl bg-red-50 border border-red-200/70 px-4 py-3 text-sm text-red-700" role="alert">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    <button type="submit"
            class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
        {{ $question ? 'Enregistrer la question' : 'Ajouter la question' }}
    </button>
</form>

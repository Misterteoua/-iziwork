{{--
    Formulaire d'une question, partagé entre l'ajout et la modification.

    Variables attendues :
      $action    URL de soumission
      $method    POST ou PUT
      $question  FormField existante, ou null pour une nouvelle question
      $slots     nombre d'emplacements de propositions affichés

    Trois types de questions :

      - « Choix unique » et « Choix multiple » : des propositions, une ou
        plusieurs bonnes réponses, corrigées automatiquement ;
      - « Réponse rédigée » : ni proposition ni bonne réponse, un guide de
        correction pour l'enseignant et une note attribuée à la main.

    Le masquage des blocs n'est qu'un confort : la validation du serveur branche
    sur le type reçu, un formulaire renvoyé à la main ne peut donc pas créer une
    question incohérente.
--}}
@php
    $slots = $slots ?? 4;
    $existingOptions = $question?->getOptionsList() ?? [];
    $correct = $question?->correctIndexes() ?? [];
    $currentType = old('field_type', $question?->field_type ?? 'radio');
@endphp

<div data-question-form>
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
            <select name="field_type" data-type-select
                    class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                <option value="radio" @selected($currentType === 'radio')>Choix unique</option>
                <option value="checkbox" @selected($currentType === 'checkbox')>Choix multiple</option>
                <option value="textarea" @selected($currentType === 'textarea')>Réponse rédigée (question ouverte)</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Barème (points)</label>
            <input type="number" name="points" step="0.5" min="0.5" max="100" required
                   value="{{ old('points', $question?->points ?? 1) }}"
                   class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
        </div>
    </div>

    <fieldset data-qcm-block class="{{ $currentType === 'textarea' ? 'hidden' : '' }}">
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

    <div data-open-block class="{{ $currentType === 'textarea' ? '' : 'hidden' }}">
        <label class="block text-sm font-medium text-slate-700 mb-1.5">Réponse attendue (guide de correction)</label>
        <textarea name="expected_answer" rows="3"
                  placeholder="Ex : saponification, huile + soude, glycérine en sous-produit…"
                  class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('expected_answer', $question?->expected_answer) }}</textarea>
        <p class="mt-2 text-xs text-slate-500">
            Facultatif, jamais montré à l'étudiant. Ce guide s'affiche sur la page de correction, à côté de sa réponse, pour vous relire d'une copie à l'autre.
        </p>
    </div>

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
</div>

@push('scripts')
<script>
(function () {
    // Le bloc des propositions et le guide de correction ne concernent pas les
    // mêmes types de questions : on montre celui qui a un sens. La validation
    // serveur reste seule juge, ceci n'est qu'un confort de saisie.
    document.querySelectorAll('[data-question-form]').forEach(function (scope) {
        const select = scope.querySelector('[data-type-select]');
        if (!select) { return; }

        function refresh() {
            const isOpen = select.value === 'textarea';

            scope.querySelectorAll('[data-qcm-block]').forEach(function (block) {
                block.classList.toggle('hidden', isOpen);
            });

            scope.querySelectorAll('[data-open-block]').forEach(function (block) {
                block.classList.toggle('hidden', !isOpen);
            });
        }

        select.addEventListener('change', refresh);
        refresh();
    });
})();
</script>
@endpush

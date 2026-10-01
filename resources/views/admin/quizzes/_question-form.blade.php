{{--
    Formulaire d'une question, partagé entre l'ajout et la modification.

    Variables attendues :
      $action    URL de soumission
      $method    POST ou PUT
      $question  FormField existante, ou null pour une nouvelle question
      $slots     nombre d'emplacements de propositions affichés
      $quiz      évaluation en cours, pour l'aperçu de l'énoncé

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
        {{-- Le plafond affiché est celui que le serveur applique
             (QuizQuestionData::MAX_LABEL_LENGTH) : laisser taper au-delà ne
             ferait que renvoyer un refus après coup. --}}
        <textarea name="field_label" rows="2" required
                  maxlength="{{ \App\Support\QuizQuestionData::MAX_LABEL_LENGTH }}"
                  placeholder="Ex : Quelle est la complexité de la recherche dichotomique ?"
                  class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('field_label', $question?->field_label) }}</textarea>

        {{-- Aperçu : il n'apparaît que si JavaScript est là, et seulement
             pendant la saisie. Un texte de question se juge mieux mis en forme
             qu'en pavé — c'est ce que verront le candidat et le correcteur. --}}
        <div data-question-preview hidden
             data-preview-url="{{ route('admin.quizzes.questions.preview', $quiz) }}"
             class="mt-3 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Aperçu de l'énoncé</p>
            <div class="mt-1 text-sm text-slate-900" data-preview-target></div>
            <p class="mt-1 text-xs text-slate-500">
                Paragraphes et énumérations mis en forme, comme sur la carte du candidat.
            </p>
        </div>
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
                  maxlength="{{ \App\Support\QuizQuestionData::MAX_EXPECTED_ANSWER }}"
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

@once
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

(function () {
    // Aperçu de l'énoncé : la mise en forme ne vit qu'au serveur
    // (App\Support\QuestionText). Ici, on demande simplement le rendu de ce qui
    // est en train d'être tapé — deux formateurs finiraient par diverger, et un
    // aperçu qui mentirait serait pire que pas d'aperçu du tout.
    const DELAY = 400;

    document.querySelectorAll('[data-question-preview]').forEach(function (block) {
        const form = block.closest('form');
        const field = form ? form.querySelector('[name="field_label"]') : null;
        const target = block.querySelector('[data-preview-target]');
        const token = form ? form.querySelector('[name="_token"]') : null;
        const url = block.getAttribute('data-preview-url');

        if (!field || !target || !url) { return; }

        let timer = null;
        let pending = null;

        function show(html) {
            if (html === '') {
                block.setAttribute('hidden', 'hidden');

                return;
            }

            // Le HTML vient de l'application, qui l'a échappé fragment par
            // fragment : l'insérer tel quel est le seul moyen de montrer la
            // mise en forme.
            target.innerHTML = html;
            block.removeAttribute('hidden');
        }

        function ask() {
            // Une frappe chasse l'autre : sans annulation, une réponse lente
            // pourrait écraser un aperçu plus récent.
            if (pending) { pending.abort(); }
            pending = new AbortController();

            const body = new URLSearchParams();
            body.set('field_label', field.value);
            if (token) { body.set('_token', token.value); }

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: body,
                signal: pending.signal
            }).then(function (response) {
                if (!response.ok) { throw new Error('apercu'); }

                return response.json();
            }).then(function (payload) {
                show(typeof payload.html === 'string' ? payload.html : '');
            }).catch(function (error) {
                // L'aperçu est un confort : s'il échoue, il disparaît plutôt que
                // d'afficher une erreur au milieu de la saisie.
                if (error && error.name === 'AbortError') { return; }

                block.setAttribute('hidden', 'hidden');
            });
        }

        field.addEventListener('input', function () {
            if (timer) { window.clearTimeout(timer); }

            timer = window.setTimeout(ask, DELAY);
        });
    });
})();
</script>
@endpush
@endonce

{{-- Une question de la copie en cours de correction, avec sa note et son
     commentaire.

     Partagé par la correction de l'administrateur et celle d'un correcteur
     externe : les deux doivent voir exactement le même écran, sinon une note
     pourrait dépendre de qui a corrigé. Seule la route du formulaire, définie
     par la vue englobante, change.

     Variables attendues : $attempt, $question, $index, $answers. --}}
@php($answer = $answers[$question->id] ?? null)

<div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6">
    <div class="flex items-start justify-between gap-4">
        <p class="text-sm font-medium text-slate-900 whitespace-pre-line">
            <span class="text-slate-400" style="font-variant-numeric: tabular-nums">{{ $index + 1 }}.</span>
            {{ $question->field_label }}
        </p>
        <span class="shrink-0 text-xs text-slate-500">{{ $question->points }} point(s)</span>
    </div>

    @if($question->isOpen())
        @if($question->expected_answer)
        <div class="mt-4 rounded-xl bg-brand-50/60 border border-brand-100 px-4 py-3">
            <p class="text-xs font-semibold text-brand-800 uppercase tracking-wider">Réponse attendue (guide)</p>
            <p class="mt-1 text-xs text-slate-700 whitespace-pre-line">{{ $question->expected_answer }}</p>
        </div>
        @endif

        <div class="mt-4 rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Réponse de l'étudiant</p>
            @if($answer === null || trim((string) $answer->answer_text) === '')
                <p class="mt-1 text-sm text-slate-500">Aucune réponse rendue : rien à corriger, la question vaut zéro.</p>
            @else
                <p class="mt-1 text-sm text-slate-800 whitespace-pre-line">{{ $answer->answer_text }}</p>
            @endif
        </div>

        @if($answer !== null && trim((string) $answer->answer_text) !== '')
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label for="points-{{ $answer->id }}" class="text-sm font-medium text-slate-700">
                Points attribués <span class="text-slate-400">/ {{ $question->points }}</span>
            </label>
            <input type="number" name="points[{{ $answer->id }}]" id="points-{{ $answer->id }}"
                   step="0.5" min="0" max="{{ $question->points }}"
                   value="{{ old('points.'.$answer->id, $answer->points_awarded) }}"
                   placeholder="En attente"
                   class="w-32 px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
            <span class="text-xs text-slate-500">
                {{ $answer->isGraded() ? 'Note enregistrée' : 'Pas encore corrigée' }} · une note partielle est acceptée
            </span>
        </div>
        @error('points.'.$answer->id)
        <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror

        {{-- Le commentaire part avec la note, et se voit dans le bulletin de
             l'étudiant dès que la copie est entièrement corrigée. --}}
        <div class="mt-4">
            <label for="comment-{{ $answer->id }}" class="text-sm font-medium text-slate-700">
                Commentaire pour l'étudiant <span class="text-slate-400">(facultatif)</span>
            </label>
            <textarea name="comments[{{ $answer->id }}]" id="comment-{{ $answer->id }}" rows="2" maxlength="2000"
                      placeholder="Ex. : les deux étapes sont justes, le calcul final est faux."
                      class="mt-2 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('comments.'.$answer->id, $answer->grader_comment) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">
                L'étudiant le lit une fois la copie entièrement corrigée, jamais sur une note provisoire.
            </p>
            @error('comments.'.$answer->id)
            <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
            @enderror
        </div>

        {{-- Qui a posé la note : sans cela, une note contestée n'a pas de
             réponse, et deux correcteurs ne peuvent pas se relayer sans savoir
             où en est la copie. --}}
        @if($answer->isGraded() && $answer->gradedByLabel())
        <p class="mt-2 text-xs text-slate-500">Note posée par {{ $answer->gradedByLabel() }}.</p>
        @endif
        @endif
    @else
        @php($chosen = $answer?->chosenIndexes() ?? [])
        @php($chosenLabels = [])
        @php($correctLabels = [])

        @foreach($attempt->displayOptions($question) as $optionPosition => $option)
            @if(in_array($option['original'], $chosen, true))
                @php($chosenLabels[] = $option['label'])
            @endif
            @if(in_array($option['original'], $question->correctIndexes(), true))
                @php($correctLabels[] = $option['label'])
            @endif
        @endforeach

        <p class="mt-3 text-xs text-slate-600">
            <span class="font-semibold text-slate-500 uppercase tracking-wider">Réponse de l'étudiant :</span>
            {{ $chosenLabels === [] ? 'sans réponse' : implode(' ; ', $chosenLabels) }}
        </p>
        <p class="mt-1 text-xs text-slate-600">
            <span class="font-semibold text-slate-500 uppercase tracking-wider">Bonne réponse :</span>
            {{ implode(' ; ', $correctLabels) }}
        </p>
        <p class="mt-2 text-xs font-medium {{ $answer?->is_correct ? 'text-emerald-700' : 'text-slate-500' }}">
            {{ $answer?->is_correct ? 'Juste — '.$answer->points_awarded.' point(s)' : 'Faux — 0 point' }}
            <span class="text-slate-400">· corrigée automatiquement</span>
        </p>
    @endif
</div>

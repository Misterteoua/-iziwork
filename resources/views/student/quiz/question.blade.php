@extends('layouts.app')

@section('title', $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">
    @include('student.quiz._proctoring', ['quiz' => $quiz, 'attempt' => $attempt, 'remaining' => $remaining])

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider" style="font-variant-numeric: tabular-nums">
            Question {{ $position }} sur {{ $total }}
        </p>

        <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100" role="presentation">
            <div class="h-1.5 rounded-full gradient-bg" style="width: {{ $total > 0 ? round($position / $total * 100) : 0 }}%"></div>
        </div>

        <h1 class="mt-6 text-lg font-semibold text-slate-900 whitespace-pre-line">{{ $question->field_label }}</h1>

        <p class="mt-1 text-xs text-slate-500">
            @if($question->isOpen())
                Réponse rédigée
            @else
                {{ $question->isMultipleAnswer() ? 'Plusieurs réponses possibles' : 'Une seule réponse' }}
            @endif
            · {{ $question->points }} point(s)
        </p>

        <form method="POST" action="{{ route('quiz.answer', $quiz->token) }}" class="mt-6 space-y-3">
            @csrf
            <input type="hidden" name="question_id" value="{{ $question->id }}">

            @if($question->isOpen())
            {{-- Question ouverte : la réponse est un texte, corrigé ensuite par
                 l'enseignant. Aucune proposition, donc aucun risque qu'une bonne
                 réponse fuite dans le HTML. --}}
            <div>
                <label for="answer_text" class="block text-sm font-medium text-slate-700 mb-1.5">Votre réponse</label>
                <textarea name="answer_text" id="answer_text" rows="7" required
                          maxlength="{{ \App\Support\QuizQuestionData::MAX_STUDENT_ANSWER }}"
                          placeholder="Rédigez votre réponse ici."
                          class="w-full px-4 py-3 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('answer_text') }}</textarea>
                <p class="mt-1.5 text-xs text-slate-500">
                    <span id="answer-counter" style="font-variant-numeric: tabular-nums">0</span>
                    / {{ \App\Support\QuizQuestionData::MAX_STUDENT_ANSWER }} caractères
                </p>
                @if($quiz->quizUsesProctoring())
                <p class="mt-1.5 text-xs text-amber-700">
                    La surveillance de fenêtre désactive le collage : votre réponse doit être saisie au clavier.
                </p>
                @endif
            </div>
            @else
            @foreach($options as $position => $option)
            <label class="flex items-start gap-3 p-4 rounded-xl border border-slate-200 hover:border-brand-300 hover:bg-brand-50/40 cursor-pointer transition-colors duration-150">
                <input type="{{ $question->isMultipleAnswer() ? 'checkbox' : 'radio' }}"
                       name="choice{{ $question->isMultipleAnswer() ? '[]' : '' }}"
                       value="{{ $option['original'] }}"
                       @checked(in_array((string) $option['original'], array_map('strval', (array) old('choice', [])), true))
                       class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40">
                <span class="text-sm text-slate-800">
                    {{-- La lettre est la position affichée, pas l'index d'origine :
                         c'est ce que le candidat voit, et elle change s'il y a mélange. --}}
                    <span class="font-semibold text-slate-500 mr-1">{{ chr(65 + $position) }}.</span> {{ $option['label'] }}
                </span>
            </label>
            @endforeach
            @endif

            @error('choice')<p class="text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            @error('answer_text')<p class="text-sm text-red-600" role="alert">{{ $message }}</p>@enderror

            <button type="submit"
                    class="w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 transition-all duration-150">
                {{ $position === $total ? 'Valider et terminer' : 'Valider et passer à la suivante' }}
            </button>
        </form>

        <p class="mt-4 text-xs text-slate-500">
            La réponse est définitive une fois validée : l'évaluation ne permet pas de revenir en arrière.
        </p>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const field = document.getElementById('answer_text');
    const counter = document.getElementById('answer-counter');

    if (!field || !counter) { return; }

    function refresh() { counter.textContent = field.value.length; }

    field.addEventListener('input', refresh);
    refresh();
})();
</script>
@endpush

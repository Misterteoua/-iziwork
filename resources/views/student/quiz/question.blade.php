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
            {{ $question->isMultipleAnswer() ? 'Plusieurs réponses possibles' : 'Une seule réponse' }}
            · {{ $question->points }} point(s)
        </p>

        <form method="POST" action="{{ route('quiz.answer', $quiz->token) }}" class="mt-6 space-y-3">
            @csrf
            <input type="hidden" name="question_id" value="{{ $question->id }}">

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

            @error('choice')<p class="text-sm text-red-600" role="alert">{{ $message }}</p>@enderror

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

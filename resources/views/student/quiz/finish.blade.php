@extends('layouts.app')

@section('title', $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">
    @include('student.quiz._proctoring', ['quiz' => $quiz, 'attempt' => $attempt, 'remaining' => $remaining])

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 text-center">
        <div class="mx-auto h-12 w-12 rounded-full bg-brand-50 flex items-center justify-center">
            <svg class="h-6 w-6 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>

        <h1 class="mt-4 text-xl font-bold tracking-tight text-slate-900">Vous avez répondu à toutes les questions</h1>
        <p class="mt-2 text-sm text-slate-600">
            {{ $answered }} réponse(s) sur {{ $total }}. Rendez votre copie pour obtenir votre note.
        </p>

        <form method="POST" action="{{ route('quiz.submit', $quiz->token) }}" class="mt-6">
            @csrf
            <button type="submit"
                    class="w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 transition-all duration-150">
                Rendre ma copie
            </button>
        </form>

        @if($quiz->quizHasOpenQuestions())
        {{-- Une question rédigée ne peut pas être notée par une machine : mieux
             vaut le dire avant le rendu qu'après. --}}
        <p class="mt-4 text-xs text-amber-700">
            Les questions à réponse rédigée seront corrigées par votre enseignant.
            Votre note sera provisoire jusqu'à cette correction.
        </p>
        @endif

        <p class="mt-4 text-xs text-slate-500">
            @if($quiz->quizHasOpenQuestions())
                Vos réponses sont définitives une fois la copie rendue.
            @else
                La correction est définitive.
            @endif
            Si le temps s'écoule avant que vous ne validiez, la copie est rendue
            automatiquement avec les réponses déjà enregistrées.
        </p>
    </div>
</div>
@endsection

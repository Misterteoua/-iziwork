@extends('layouts.app')

@section('title', 'Évaluations')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Évaluations en ligne</h1>
            <p class="mt-1 text-sm text-slate-500">Questionnaires notés, chronométrés et corrigés automatiquement</p>
        </div>
        <a href="{{ route('admin.quizzes.create') }}"
           class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover transition-all duration-150 shrink-0">
            <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nouvelle évaluation
        </a>
    </div>

    @if($quizzes->isEmpty())
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-12 text-center">
        <div class="mx-auto h-12 w-12 rounded-full bg-slate-100 flex items-center justify-center mb-3">
            <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        </div>
        <p class="text-sm font-medium text-slate-700">Aucune évaluation</p>
        <p class="mt-1 text-sm text-slate-500">Créez votre premier questionnaire pour interroger les étudiants en ligne.</p>
    </div>
    @else
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
        @foreach($quizzes as $quiz)
        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 hover:shadow-card-hover transition-shadow duration-200">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="{{ route('admin.quizzes.show', $quiz) }}" class="text-base font-semibold text-slate-900 hover:text-brand-700 transition-colors duration-150">
                        {{ $quiz->title }}
                    </a>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $quiz->quizDurationMinutes() }} min · {{ $quiz->quizMaxScore() }} point(s)
                        @if($quiz->is_anonymous) · anonyme @endif
                    </p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium shrink-0 {{ $quiz->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $quiz->status === 'active' ? 'bg-emerald-500' : 'bg-slate-400' }}" aria-hidden="true"></span>
                    {{ $quiz->status === 'active' ? 'Ouverte' : 'Fermée' }}
                </span>
            </div>

            <dl class="grid grid-cols-3 gap-3 mt-5">
                <div class="rounded-xl bg-slate-50 px-3 py-2">
                    <dt class="text-xs text-slate-500">Questions</dt>
                    <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $quiz->questions_count }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2">
                    <dt class="text-xs text-slate-500">Participants</dt>
                    <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $quiz->attempts_count }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2">
                    <dt class="text-xs text-slate-500">Rendues</dt>
                    <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $quiz->submitted_count }}</dd>
                </div>
            </dl>

            <div class="flex flex-wrap items-center gap-3 mt-5">
                <a href="{{ route('admin.quizzes.show', $quiz) }}"
                   class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-lg text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                    Configurer
                </a>
                <a href="{{ route('admin.quizzes.results', $quiz) }}"
                   class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-lg text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                    Résultats
                </a>
                <form method="POST" action="{{ route('admin.quizzes.toggle', $quiz) }}" class="ml-auto">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="inline-flex items-center justify-center px-3.5 py-2 border border-transparent text-xs font-semibold rounded-lg text-white {{ $quiz->status === 'active' ? 'bg-slate-600 hover:bg-slate-700' : 'bg-brand-600 hover:bg-brand-700' }} transition-colors duration-150">
                        {{ $quiz->status === 'active' ? 'Fermer' : 'Ouvrir' }}
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection

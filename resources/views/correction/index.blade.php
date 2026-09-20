@extends('layouts.app')

@section('title', 'Mes copies à corriger')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Mes copies à corriger</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $grader->name }} ·
                {{ $quizzes->isEmpty() ? 'aucune évaluation affectée' : $quizzes->pluck('title')->join(' · ') }}
            </p>
        </div>

        @if($graded->isNotEmpty())
        <a href="{{ route('correction.export') }}"
           class="inline-flex items-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
            Mes corrections ({{ $graded->count() }} copie(s) — CSV)
        </a>
        @endif
    </div>

    @if($grader->expires_at !== null)
    <p class="mt-3 text-xs text-slate-500">
        Échéance de votre mission :
        <span class="font-medium text-slate-700">{{ $grader->expires_at->format('d/m/Y à H:i') }}</span>
        — l'accès se ferme automatiquement à cette date.
    </p>
    @endif

    {{-- La file : uniquement les copies des évaluations affectées à ce
         correcteur, dans l'ordre de remise. --}}
    <div class="mt-8 bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold text-slate-900">
                Copies en attente
                <span class="ml-1 text-sm font-normal text-slate-500">({{ $queue->count() }})</span>
            </h2>

            @if($queue->isNotEmpty())
            <a href="{{ route('correction.show', [$queue->first(), 'serie' => 1]) }}"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                Corriger la première (série)
            </a>
            @endif
        </div>

        @if($queue->isEmpty())
        <p class="px-6 py-8 text-sm text-slate-500">
            Rien à corriger pour l'instant : aucune copie rendue n'attend de note de votre part.
        </p>
        @else
        <ul class="divide-y divide-slate-100">
            @foreach($queue as $attempt)
            <li class="px-6 py-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900 font-mono">{{ $attempt->reference }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ $attempt->form?->title }}
                        @unless($attempt->form?->is_anonymous)
                            @if($attempt->student_name) · {{ $attempt->student_name }} @endif
                        @endunless
                        · remise {{ $attempt->submitted_at?->format('d/m/Y à H:i') ?? '—' }}
                    </p>
                </div>

                <a href="{{ route('correction.show', $attempt) }}"
                   class="inline-flex items-center px-3 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                    Corriger
                </a>
            </li>
            @endforeach
        </ul>
        @endif
    </div>

    @if($graded->isNotEmpty())
    <div class="mt-8 bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h2 class="text-base font-semibold text-slate-900">Copies où vous avez laissé une trace</h2>
            <p class="mt-1 text-xs text-slate-500">
                C'est exactement ce que contient votre export CSV : vos notes et vos commentaires, et rien d'autre.
            </p>
        </div>

        <ul class="divide-y divide-slate-100">
            @foreach($graded as $attempt)
            <li class="px-6 py-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900 font-mono">{{ $attempt->reference }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ $attempt->form?->title }}
                        @unless($attempt->form?->is_anonymous)
                            @if($attempt->student_name) · {{ $attempt->student_name }} @endif
                        @endunless
                    </p>

                    {{-- Une note reprise : le correcteur le sait, plutôt que de le
                         découvrir en rouvrant la copie des semaines plus tard. --}}
                    @if($attempt->revisions_count > 0)
                    <p class="mt-1 text-xs font-medium text-amber-700">
                        {{ $attempt->revisions_count }} note(s) reprise(s) par l'administration
                    </p>
                    @endif
                </div>

                <a href="{{ route('correction.show', $attempt) }}"
                   class="text-xs font-medium text-brand-700 hover:text-brand-800">
                    Revoir la copie
                </a>
            </li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
@endsection

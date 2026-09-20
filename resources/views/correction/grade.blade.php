@extends('layouts.app')

@section('title', 'Corriger une copie - ' . $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-3xl mx-auto">
    <div class="mb-8">
        <a href="{{ route('correction.index') }}"
           class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour à mes copies
        </a>

        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Corriger la copie</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ $quiz->title }} · référence
            <span class="font-mono">{{ $attempt->reference }}</span>
            @unless($quiz->is_anonymous)
                @if($attempt->student_name) · {{ $attempt->student_name }} @endif
            @endunless
        </p>

        @if($series)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-brand-50/60 border border-brand-100 px-4 py-3">
            <p class="text-sm text-brand-900" style="font-variant-numeric: tabular-nums">
                <span class="font-semibold">Correction en série</span>
                @if($position !== null)
                    · copie {{ $position }} sur {{ $queueSize }}
                @else
                    · copie déjà corrigée
                @endif
            </p>

            <div class="flex items-center gap-3">
                @if($next)
                <a href="{{ route('correction.show', [$next, 'serie' => 1]) }}"
                   class="text-xs font-semibold text-brand-800 hover:text-brand-900">
                    Passer cette copie
                </a>
                @endif
                <a href="{{ route('correction.index') }}"
                   class="text-xs font-medium text-slate-600 hover:text-slate-800">
                    Quitter la série
                </a>
            </div>
        </div>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 mb-8">
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Note actuelle</dt>
                <dd class="text-sm font-semibold text-slate-900" style="font-variant-numeric: tabular-nums">
                    {{ rtrim(rtrim(number_format((float) $attempt->score, 2, ',', ' '), '0'), ',') }}
                    / {{ rtrim(rtrim(number_format((float) $attempt->max_score, 2, ',', ' '), '0'), ',') }}
                    @if($pending > 0)
                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">provisoire</span>
                    @endif
                </dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Réponses à corriger</dt>
                <dd class="text-sm font-semibold {{ $pending > 0 ? 'text-amber-700' : 'text-emerald-700' }}" style="font-variant-numeric: tabular-nums">
                    {{ $pending }}
                </dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Temps utilisé</dt>
                <dd class="text-sm font-semibold text-slate-900" style="font-variant-numeric: tabular-nums">
                    {{ $attempt->started_at ? gmdate('i:s', $attempt->elapsedSeconds()) : '—' }}
                </dd>
            </div>
        </dl>

        @if($pending > 0)
        <p class="mt-4 text-xs text-slate-500">
            Tant qu'une réponse reste en attente, la note affichée à l'étudiant est annoncée comme provisoire.
            Laissez un champ vide pour garder une réponse en attente et corriger la copie en plusieurs fois.
        </p>
        @endif
    </div>

    <form method="POST" action="{{ route('correction.store', $attempt) }}" class="space-y-4">
        @csrf
        @if($series)
        <input type="hidden" name="serie" value="1">
        @endif

        {{-- La carte d'une question est la même que dans l'espace
             d'administration : une seule mise en page, donc une seule façon de
             noter, quel que soit celui qui corrige. --}}
        @foreach($questions as $index => $question)
            @include('partials.grading-answer', [
                'attempt' => $attempt,
                'question' => $question,
                'index' => $index,
                'answers' => $answers,
                // Un correcteur voit la relecture de l'administration, mais
                // ne peut pas reprendre une note déjà relue.
                'asAdmin' => false,
            ])
        @endforeach

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                {{ $series && $next ? 'Enregistrer et passer à la suivante' : 'Enregistrer la correction' }}
            </button>
            <a href="{{ route('correction.index') }}"
               class="inline-flex items-center justify-center px-5 py-3 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                {{ $series ? 'Quitter la série' : 'Annuler' }}
            </a>
        </div>

        @if($series && ! $next)
        <p class="text-xs text-slate-500">
            C'est la dernière copie en attente : l'enregistrement vous ramènera à vos copies.
        </p>
        @endif
    </form>
</div>
@endsection

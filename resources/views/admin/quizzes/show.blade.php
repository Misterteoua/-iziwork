@extends('layouts.app')

@section('title', $quiz->title)

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.index') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux évaluations
        </a>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $quiz->title }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $quiz->quizDurationMinutes() }} min ·
                    {{ $questions->count() }} question(s) ·
                    {{ $quiz->quizMaxScore() }} point(s) ·
                    {{ $quiz->is_anonymous ? 'anonyme' : 'nominative' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('admin.quizzes.toggle', $quiz) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white {{ $quiz->status === 'active' ? 'bg-slate-600 hover:bg-slate-700' : 'bg-brand-600 hover:bg-brand-700' }} transition-colors duration-150">
                        {{ $quiz->status === 'active' ? 'Fermer l\'évaluation' : 'Ouvrir l\'évaluation' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.quizzes.destroy', $quiz) }}"
                      onsubmit="return confirm('Supprimer cette évaluation et toutes les participations associées ?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 border border-red-200 text-sm font-semibold rounded-xl text-red-700 hover:bg-red-50 transition-colors duration-150">
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Lien étudiant --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 mb-8">
        <h2 class="text-base font-semibold text-slate-900 mb-2">Lien à communiquer aux étudiants</h2>
        <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" readonly value="{{ route('quiz.start', $quiz->token) }}"
                   aria-label="Lien de l'évaluation"
                   onfocus="this.select()"
                   class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-700 bg-slate-50">
            <a href="{{ route('quiz.start', $quiz->token) }}" target="_blank" rel="noopener"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150 shrink-0">
                Ouvrir
            </a>
        </div>
        @unless($quiz->status === 'active')
        <p class="mt-2 text-xs text-amber-600">L'évaluation est fermée : les étudiants verront un message d'indisponibilité tant que vous ne l'ouvrez pas.</p>
        @endunless
    </div>

    {{-- Réglages --}}
    <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 mb-8">
        <h2 class="text-base font-semibold text-slate-900 mb-5">Réglages de l'épreuve</h2>

        <form method="POST" action="{{ route('admin.quizzes.update', $quiz) }}" class="space-y-5">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2">
                    <label for="title" class="block text-sm font-medium text-slate-700 mb-1.5">Titre</label>
                    <input type="text" name="title" id="title" required value="{{ old('title', $quiz->title) }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-slate-700 mb-1.5">Consignes</label>
                    <textarea name="description" id="description" rows="3"
                              class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('description', $quiz->description) }}</textarea>
                </div>

                <div>
                    <label for="duration_minutes" class="block text-sm font-medium text-slate-700 mb-1.5">Durée (minutes)</label>
                    <input type="number" name="duration_minutes" id="duration_minutes" required min="1" max="600"
                           value="{{ old('duration_minutes', $quiz->quizDurationMinutes()) }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                </div>

                <div>
                    <label for="max_submissions" class="block text-sm font-medium text-slate-700 mb-1.5">Quota de participants</label>
                    <input type="number" name="max_submissions" id="max_submissions" min="1" max="100000"
                           value="{{ old('max_submissions', $quiz->max_submissions) }}" placeholder="Illimité"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                </div>

                <div>
                    <label for="open_date" class="block text-sm font-medium text-slate-700 mb-1.5">Ouverture</label>
                    <input type="datetime-local" name="open_date" id="open_date"
                           value="{{ old('open_date', $quiz->open_date?->format('Y-m-d\TH:i')) }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                </div>

                <div>
                    <label for="close_date" class="block text-sm font-medium text-slate-700 mb-1.5">Fermeture</label>
                    <input type="datetime-local" name="close_date" id="close_date"
                           value="{{ old('close_date', $quiz->close_date?->format('Y-m-d\TH:i')) }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                </div>
            </div>

            <div class="space-y-3">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="is_anonymous" value="1" @checked(old('is_anonymous', $quiz->is_anonymous))
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600">
                    <span class="text-sm text-slate-700">Évaluation anonyme (connexion par référence, aucun nom enregistré)</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="show_score" value="1" @checked(old('show_score', $quiz->quizShowsScore()))
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600">
                    <span class="text-sm text-slate-700">Afficher la note à la fin</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="proctoring" value="1" @checked(old('proctoring', $quiz->quizUsesProctoring()))
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600">
                    <span class="text-sm text-slate-700">Surveillance de la fenêtre (plein écran, copier-coller bloqué, journal des sorties)</span>
                </label>
            </div>

            <button type="submit"
                    class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                Enregistrer les réglages
            </button>
        </form>
    </section>

    {{-- Questions --}}
    <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden mb-8">
        <div class="px-6 py-5 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900">Questions</h2>
            <p class="text-xs text-slate-500">{{ $questions->count() }} question(s) · {{ $quiz->quizMaxScore() }} point(s) au total</p>
        </div>

        @if($questions->isNotEmpty())
        <ul class="divide-y divide-slate-100">
            @foreach($questions as $index => $question)
            <li class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-900">
                            <span class="text-slate-400" style="font-variant-numeric: tabular-nums">{{ $index + 1 }}.</span>
                            {{ $question->field_label }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $question->isMultipleAnswer() ? 'Choix multiple' : 'Choix unique' }} ·
                            {{ $question->points }} point(s) ·
                            {{ count($question->getOptionsList()) }} proposition(s)
                        </p>
                        <ul class="mt-2 space-y-0.5">
                            @foreach($question->getOptionsList() as $optionIndex => $option)
                            <li class="text-xs {{ in_array($optionIndex, $question->correctIndexes(), true) ? 'text-emerald-700 font-medium' : 'text-slate-500' }}">
                                {{ chr(65 + $optionIndex) }}. {{ $option }}
                                @if(in_array($optionIndex, $question->correctIndexes(), true))<span aria-label="bonne réponse">✓</span>@endif
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    <form method="POST" action="{{ route('admin.quizzes.questions.destroy', [$quiz, $question]) }}"
                          onsubmit="return confirm('Supprimer cette question ?');" class="shrink-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center justify-center px-3 py-1.5 border border-red-200 text-xs font-semibold rounded-lg text-red-700 hover:bg-red-50 transition-colors duration-150">
                            Supprimer
                        </button>
                    </form>
                </div>

                <details class="mt-4">
                    <summary class="cursor-pointer text-xs font-semibold text-brand-700 hover:text-brand-800">Modifier cette question</summary>
                    <div class="mt-4 rounded-xl bg-slate-50 p-4">
                        @include('admin.quizzes._question-form', [
                            'action' => route('admin.quizzes.questions.update', [$quiz, $question]),
                            'method' => 'PUT',
                            'question' => $question,
                            'slots' => max(4, count($question->getOptionsList())),
                        ])
                    </div>
                </details>
            </li>
            @endforeach
        </ul>
        @else
        <div class="p-8 text-center">
            <p class="text-sm font-medium text-slate-700">Aucune question pour l'instant</p>
            <p class="mt-1 text-sm text-slate-500">Ajoutez votre première question ci-dessous.</p>
        </div>
        @endif

        <div class="px-6 py-5 border-t border-slate-100 bg-slate-50/60">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Ajouter une question</h3>
            @include('admin.quizzes._question-form', [
                'action' => route('admin.quizzes.questions.store', $quiz),
                'method' => 'POST',
                'question' => null,
                'slots' => 4,
            ])
        </div>
    </section>

    {{-- Références --}}
    <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 mb-8">
        <h2 class="text-base font-semibold text-slate-900 mb-2">Références des participants</h2>

        @if($quiz->quizHasPreparedReferences())
        <p class="text-sm text-slate-600">
            L'évaluation fonctionne par liste : chaque étudiant doit saisir l'une de ces références.
            Une référence inconnue est refusée.
        </p>

        <label for="references-list" class="sr-only">Liste des références</label>
        <textarea id="references-list" readonly rows="{{ min(6, max(2, (int) ceil($attempts->count() / 6))) }}"
                  class="mt-4 w-full px-4 py-3 border border-slate-300 rounded-xl text-sm text-slate-700 bg-slate-50 font-mono">{{ $attempts->pluck('reference')->implode("\n") }}</textarea>
        @else
        <p class="text-sm text-slate-600">
            Mode libre : l'étudiant s'identifie en arrivant et une référence lui est attribuée automatiquement.
            Générez plutôt des références à l'avance pour maîtriser qui participe — dès la première référence créée,
            l'évaluation exige une référence.
        </p>
        @endif

        <form method="POST" action="{{ route('admin.quizzes.references.store', $quiz) }}" class="flex flex-wrap items-end gap-3 mt-5">
            @csrf
            <div>
                <label for="count" class="block text-sm font-medium text-slate-700 mb-1.5">Générer</label>
                <input type="number" name="count" id="count" min="1" max="500" required value="{{ old('count', 10) }}"
                       class="w-28 px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
            </div>
            <button type="submit"
                    class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                Générer des références
            </button>
            <span class="text-xs text-slate-500">10 caractères, sans caractères ambigus (ni O/0, ni I/1/L).</span>
        </form>

        @if($attempts->isNotEmpty())
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.quizzes.results', $quiz) }}"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                Voir les résultats ({{ $attempts->count() }})
            </a>
        </div>
        @endif
    </section>
</div>
@endsection

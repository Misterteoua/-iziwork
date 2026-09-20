@extends('layouts.app')

@section('title', 'Résultats - ' . $quiz->title)

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.show', $quiz) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour à l'évaluation
        </a>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Résultats</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $quiz->title }} · {{ $attempts->count() }} participation(s)
                    @if($quiz->is_anonymous) · évaluation anonyme @endif
                </p>
            </div>
            @if($attempts->isNotEmpty())
            <div class="flex flex-wrap gap-2 shrink-0">
                <a href="{{ route('admin.quizzes.results.export', $quiz) }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                    <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Exporter en CSV
                </a>

                @if($quiz->quizHasOpenQuestions())
                {{-- Les textes rédigés se lisent groupés, pas dans un tableau :
                     une ligne par réponse, avec ce qui reste à noter. --}}
                <a href="{{ route('admin.quizzes.results.open-answers', $quiz) }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                    Réponses rédigées (CSV)
                </a>
                @endif
            </div>
            @endif
        </div>

        @php($toBeGraded = $attempts->filter(fn ($attempt) => $attempt->pending_manual_count > 0)->count())
        @if($toBeGraded > 0)
        <div class="mt-4 rounded-xl bg-amber-50 border border-amber-200/70 px-3 py-3">
            <p class="inline-flex items-start gap-2 text-xs text-amber-800">
                <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>
                    {{ $toBeGraded }} copie(s) attendent une correction : les réponses rédigées ne sont pas notées automatiquement.
                    Les notes correspondantes sont provisoires pour les étudiants.
                </span>
            </p>

            {{-- Enchaîner les copies évite de revenir à cette liste entre deux
                 corrections ; c'est le chemin normal quand une promotion entière
                 a rendu sa copie. --}}
            <a href="{{ route('admin.quizzes.grade', $quiz) }}"
               class="mt-3 inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                Corriger les copies à corriger ({{ $toBeGraded }})
            </a>
        </div>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        @if($attempts->isEmpty())
        <div class="p-12 text-center">
            <p class="text-sm font-medium text-slate-700">Aucune participation</p>
            <p class="mt-1 text-sm text-slate-500">Générez des références puis ouvrez l'évaluation pour que les étudiants puissent commencer.</p>
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Référence</th>
                        @unless($quiz->is_anonymous)
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Étudiant</th>
                        @endunless
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Statut</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Note</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Temps</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Correction</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Sorties de fenêtre</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($attempts as $attempt)
                    <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-slate-900">{{ $attempt->reference }}</td>
                        @unless($quiz->is_anonymous)
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-slate-900">{{ $attempt->student_name ?? '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $attempt->student_email }}</div>
                        </td>
                        @endunless
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                {{ $attempt->status === \App\Models\QuizAttempt::STATUS_SUBMITTED ? 'bg-emerald-50 text-emerald-700' : '' }}
                                {{ $attempt->status === \App\Models\QuizAttempt::STATUS_EXPIRED ? 'bg-amber-50 text-amber-700' : '' }}
                                {{ $attempt->status === \App\Models\QuizAttempt::STATUS_IN_PROGRESS ? 'bg-brand-50 text-brand-700' : '' }}
                                {{ $attempt->status === \App\Models\QuizAttempt::STATUS_PENDING ? 'bg-slate-100 text-slate-600' : '' }}">
                                {{ ['submitted' => 'Terminée', 'expired' => 'Temps écoulé', 'in_progress' => 'En cours', 'pending' => 'Non commencée'][$attempt->status] ?? $attempt->status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900" style="font-variant-numeric: tabular-nums">
                            @if($attempt->score === null)
                                —
                            @else
                                <span class="font-semibold">{{ rtrim(rtrim(number_format((float) $attempt->score, 2, ',', ' '), '0'), ',') }}</span>
                                <span class="text-slate-500">/ {{ rtrim(rtrim(number_format((float) $attempt->max_score, 2, ',', ' '), '0'), ',') }}</span>
                                @if($attempt->pending_manual_count > 0)
                                <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">provisoire</span>
                                @endif
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600" style="font-variant-numeric: tabular-nums">
                            {{ $attempt->started_at ? gmdate('i:s', $attempt->elapsedSeconds()) : '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            @if($attempt->pending_manual_count > 0)
                            <a href="{{ route('admin.quizzes.attempts.grade', [$quiz, $attempt]) }}"
                               class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                                Corriger ({{ $attempt->pending_manual_count }})
                            </a>
                            @elseif($attempt->isFinished())
                            <a href="{{ route('admin.quizzes.attempts.grade', [$quiz, $attempt]) }}"
                               class="text-xs font-semibold text-slate-600 hover:text-brand-600 transition-colors duration-150 whitespace-nowrap">
                                Voir la copie
                            </a>
                            @else
                            <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm {{ $attempt->infraction_count > 0 ? 'text-amber-700 font-semibold' : 'text-slate-500' }}" style="font-variant-numeric: tabular-nums">
                            {{ $attempt->infraction_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <form method="POST" action="{{ route('admin.quizzes.attempts.reset', [$quiz, $attempt]) }}"
                                  onsubmit="return confirm('Réinitialiser cette participation ? Les réponses seront effacées et l\'étudiant pourra repasser l\'épreuve.');">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700 bg-slate-100 hover:bg-slate-200 transition-colors duration-150">
                                    Réinitialiser
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    @if($quiz->quizUsesProctoring() && $attempts->sum('infraction_count') > 0)
    <p class="mt-4 text-xs text-slate-500">
        Les sorties de fenêtre sont journalisées, jamais sanctionnées automatiquement : à vous d'apprécier.
        Le détail horodaté figure dans l'export CSV de chaque participation.
    </p>
    @endif
</div>
@endsection

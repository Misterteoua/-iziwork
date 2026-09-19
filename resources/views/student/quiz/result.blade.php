@extends('layouts.app')

@section('title', 'Résultat - ' . $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">
    @php($answered = $answers->keyBy('form_field_id'))

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        <div class="text-center">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                {{ $attempt->status === \App\Models\QuizAttempt::STATUS_EXPIRED ? 'Temps écoulé — copie rendue automatiquement' : 'Évaluation terminée' }}
            </p>

            @if($showScore && $attempt->score !== null)
            <p class="mt-3 text-5xl font-bold tracking-tight text-slate-900" style="font-variant-numeric: tabular-nums">
                {{ rtrim(rtrim(number_format((float) $attempt->score, 2, ',', ' '), '0'), ',') }}
                <span class="text-2xl text-slate-400">/ {{ rtrim(rtrim(number_format((float) $attempt->max_score, 2, ',', ' '), '0'), ',') }}</span>
            </p>
            <p class="mt-1 text-sm text-slate-500">
                {{ $pending > 0 ? 'Note provisoire' : 'Votre note' }}
            </p>

            @if($pending > 0)
            {{-- Une réponse rédigée ne peut pas être notée par une machine : tant
                 qu'elle attend, la note est annoncée comme provisoire. Dire
                 l'inverse exposerait l'enseignant à une contestation légitime. --}}
            <p class="mt-4 inline-flex items-start gap-2 text-left text-xs text-amber-800 bg-amber-50 border border-amber-200/70 px-3 py-2 rounded-xl">
                <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>
                    {{ $pending }} réponse(s) rédigée(s) en attente de correction.
                    Votre note deviendra définitive après la correction par votre enseignant.
                </span>
            </p>
            @endif
            @else
            <p class="mt-3 text-lg font-semibold text-slate-900">Votre copie a bien été enregistrée</p>
            <p class="mt-1 text-sm text-slate-500">Votre enseignant communiquera les résultats.</p>
            @endif
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-8">
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Référence</dt>
                <dd class="text-sm font-mono font-semibold text-slate-900 break-all">{{ $attempt->reference }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Temps utilisé</dt>
                <dd class="text-sm font-semibold text-slate-900" style="font-variant-numeric: tabular-nums">{{ gmdate('i:s', $attempt->elapsedSeconds()) }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Date</dt>
                <dd class="text-sm font-semibold text-slate-900">{{ ($attempt->submitted_at ?? now())->format('d/m/Y H:i') }}</dd>
            </div>
        </dl>

        <a href="{{ route('quiz.recap.pdf', [$quiz->token, $attempt->reference]) }}"
           class="mt-6 w-full inline-flex items-center justify-center px-5 py-3 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
            <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Télécharger mon récapitulatif (PDF)
        </a>
        <p class="mt-2 text-xs text-slate-500 text-center">
            Conservez votre référence : elle permet de retélécharger ce document à tout moment,
            avec la note définitive une fois la correction faite.
        </p>
    </div>

    @if($showScore)
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden mt-8">
        <div class="px-6 py-5 border-b border-slate-100">
            <h2 class="text-base font-semibold text-slate-900">Détail de la correction</h2>
        </div>

        <ul class="divide-y divide-slate-100">
            @foreach($questions as $index => $question)
            @php($answer = $answered[$question->id] ?? null)
            @php($chosen = $answer?->chosenIndexes() ?? [])
            @php($awarded = $answer?->awardedPoints())
            <li class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <p class="text-sm font-medium text-slate-900 whitespace-pre-line">
                        <span class="text-slate-400" style="font-variant-numeric: tabular-nums">{{ $index + 1 }}.</span>
                        {{ $question->field_label }}
                    </p>
                    <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                        @if($answer === null) bg-slate-100 text-slate-600
                        @elseif($question->isOpen() && $awarded === null) bg-amber-50 text-amber-700
                        @elseif($awarded !== null && $awarded > 0) bg-emerald-50 text-emerald-700
                        @else bg-red-50 text-red-700
                        @endif">
                        @if($answer === null)
                            Sans réponse
                        @elseif($question->isOpen())
                            {{ $awarded === null
                                ? 'En attente de correction'
                                : rtrim(rtrim(number_format($awarded, 2, ',', ' '), '0'), ',').' / '.$question->points.' pt' }}
                        @else
                            {{ $answer->is_correct ? '+'.$question->points.' pt' : '0 pt' }}
                        @endif
                    </span>
                </div>

                @if($question->isOpen())
                {{-- Sa propre réponse, telle qu'il l'a écrite : c'est la seule
                     chose vérifiable pour une question rédigée, il n'y a pas de
                     bonne réponse à comparer. --}}
                <div class="mt-3 rounded-xl bg-slate-50 px-4 py-3">
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Votre réponse</p>
                    <p class="mt-1 text-sm text-slate-800 whitespace-pre-line">
                        {{ trim((string) $answer?->answer_text) !== '' ? $answer->answer_text : 'Aucune réponse rendue.' }}
                    </p>
                </div>
                @else
                <ul class="mt-3 space-y-1">
                    {{-- Propositions dans l'ordre reçu par ce candidat : afficher
                         l'ordre d'origine lui ferait relire une autre épreuve. --}}
                    @foreach($attempt->displayOptions($question) as $position => $option)
                    @php($isCorrect = in_array($option['original'], $question->correctIndexes(), true))
                    @php($isChosen = in_array($option['original'], $chosen, true))
                    <li class="text-xs flex items-start gap-2
                        {{ $isCorrect ? 'text-emerald-700 font-medium' : ($isChosen ? 'text-red-700' : 'text-slate-500') }}">
                        <span aria-hidden="true">{{ $isCorrect ? '✓' : ($isChosen ? '✗' : '·') }}</span>
                        <span>
                            {{ chr(65 + $position) }}. {{ $option['label'] }}
                            @if($isChosen) <span class="text-slate-400">(votre réponse)</span> @endif
                        </span>
                    </li>
                    @endforeach
                </ul>
                @endif
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Salle informatique : l'étudiant suivant ne doit pas rester sur cette page.
         La route refuse d'abandonner une épreuve en cours, seul un rendu se
         détache. --}}
    <form method="POST" action="{{ route('quiz.new-candidate', $quiz->token) }}" class="mt-8 text-center">
        @csrf
        <button type="submit" class="text-xs font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            Ce n'est pas ma copie — laisser la place à un autre étudiant
        </button>
    </form>
</div>
@endsection

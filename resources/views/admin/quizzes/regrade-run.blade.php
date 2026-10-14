@extends('layouts.app')

@section('title', 'Copies renotées - ' . $quiz->title)

@section('content')
@php($note = static fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ','))
@php($copies = $run->changedCopies())
<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.regrades', $quiz) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour au journal
        </a>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Copies renotées</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $quiz->title }} · {{ $run->originLabel() }} ·
                    <span style="font-variant-numeric: tabular-nums">{{ $run->created_at->format('d/m/Y à H:i') }}</span>
                    · par {{ $run->actorLabel() }}
                </p>
                @if($run->question_label)
                {{-- L'énoncé tel qu'il était au moment du geste : c'est lui qui a
                     été renoté, même si la question a changé de libellé depuis. --}}
                <p class="mt-2 text-sm text-slate-700">
                    <span class="text-slate-500">Question :</span> {{ \Illuminate\Support\Str::limit($run->question_label, 160) }}
                </p>
                @endif
            </div>
            <a href="{{ route('admin.quizzes.results', $quiz) }}"
               class="inline-flex items-center justify-center shrink-0 px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                Voir les résultats
            </a>
        </div>
    </div>

    @if($copies !== [])
    {{-- L'avant est celui qui était enregistré, l'après celui que la machine a
         calculé : les deux viennent du même calcul que l'aperçu, lu avant
         l'écriture. --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Référence</th>
                        @unless($quiz->is_anonymous)
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Étudiant</th>
                        @endunless
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Avant</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Après</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Détail</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($copies as $copy)
                    @php($attempt = $attempts->get($copy['attempt_id'] ?? 0))
                    @php($from = $copy['from'] ?? null)
                    @php($to = $copy['to'] ?? null)
                    @php($moved = $from === null ? 0 : ($to > $from ? 1 : ($to < $from ? -1 : 0)))
                    <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm">
                            @if($attempt)
                            <a href="{{ route('admin.quizzes.attempts.grade', [$quiz, $attempt]) }}"
                               class="text-brand-600 hover:underline">{{ $copy['reference'] }}</a>
                            @else
                            {{-- La participation a été supprimée depuis le recalcul :
                                 la ligne reste, le lien n'a plus d'où revenir. --}}
                            <span class="text-slate-500" title="Cette participation a été supprimée">{{ $copy['reference'] }}</span>
                            @endif
                        </td>
                        @unless($quiz->is_anonymous)
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($attempt)
                            <div class="text-sm font-medium text-slate-900">{{ $attempt->student_name ?? '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $attempt->student_email }}</div>
                            @else
                            <span class="text-sm text-slate-400">copie supprimée</span>
                            @endif
                        </td>
                        @endunless
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600" style="font-variant-numeric: tabular-nums">
                            {{ $note($from) }} <span class="text-slate-400">/ {{ $note($copy['max_from'] ?? null) }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold" style="font-variant-numeric: tabular-nums">
                            <span class="{{ $moved < 0 ? 'text-red-700' : ($moved > 0 ? 'text-emerald-700' : 'text-slate-900') }}">{{ $note($to) }}</span>
                            <span class="font-normal text-slate-500">/ {{ $note($copy['max_to'] ?? null) }}</span>
                            @if(($copy['max_from'] ?? null) !== null && ($copy['max_to'] ?? null) !== ($copy['max_from'] ?? null))
                            <span class="block text-xs font-normal text-slate-500">
                                barème : {{ $note($copy['max_from']) }} → {{ $note($copy['max_to']) }}
                            </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                            @if(($copy['answers'] ?? 0) > 0)
                            {{-- Une question supprimée n'a pas renoté de réponse : elle les
                                 a emportées avec elle. Le mot juste, au même endroit. --}}
                            {{ $copy['answers'] }} réponse(s)
                            {{ $run->origin === \App\Models\QuizRegradeRun::ORIGIN_DELETED ? 'retirée(s) avec la question' : 'renotée(s)' }}
                            @else
                            <span class="text-slate-400">barème seul</span>
                            @endif

                            @if(($copy['released'] ?? 0) > 0)
                            <span class="block mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                                    repasse en correction
                                </span>
                            </span>
                            @elseif(($copy['reclaimed'] ?? 0) > 0)
                            <span class="block mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                                    n'attend plus de correction
                                </span>
                            </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        Seules les notes produites par la machine figurent ici : une note posée par un correcteur ou par
        l'administration n'est jamais recalculée, sauf quand la question qui la portait est supprimée —
        elle disparaît alors avec elle.
    </p>
    @elseif($run->copies_detail === null && $run->copies > 0)
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-8 text-center">
        <p class="text-sm font-medium text-slate-700">Le détail de ce recalcul n'a pas été conservé</p>
        <p class="mt-1 text-sm text-slate-500">
            Ce recalcul est antérieur au journal des copies : on sait combien de notes ont changé
            ({{ $run->copies }}), pas lesquelles.
        </p>
    </div>
    @else
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-8 text-center">
        <p class="text-sm font-medium text-slate-700">Ce recalcul n'a changé aucune note</p>
        <p class="mt-1 text-sm text-slate-500">
            Il a été lancé, la configuration était déjà la bonne : aucune copie rendue n'avait besoin
            d'être renotée.
        </p>
    </div>
    @endif
</div>
@endsection

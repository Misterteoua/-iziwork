@extends('layouts.app')

@section('title', 'Aperçu du recalcul - ' . $quiz->title)

@section('content')
@php($note = static fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ','))
<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.results', $quiz) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux résultats
        </a>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Aperçu du recalcul</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $quiz->title }} · {{ $preview['finished'] }} copie(s) rendue(s)
                    @if($quiz->is_anonymous) · évaluation anonyme @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                @if($preview['changes'])
                {{-- Le geste lui-même, demandé depuis son aperçu : c'est ici que
                     l'enseignant décide, après avoir vu quelles notes changent,
                     et c'est ici que la question se pose une seule fois. --}}
                <form method="POST" action="{{ route('admin.quizzes.regrade', $quiz) }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                        <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h5M20 20v-5h-5M4.6 15a8 8 0 0014 1M19.4 9a8 8 0 00-14-1" />
                        </svg>
                        Confirmer le recalcul
                    </button>
                </form>
                @endif

                <a href="{{ route('admin.quizzes.results', $quiz) }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                    {{ $preview['changes'] ? 'Annuler' : 'Retour aux résultats' }}
                </a>
            </div>
        </div>
    </div>

    @if($preview['copies'] > 0)
    {{-- Ce que le recalcul ferait, avant qu'il ne le fasse. La colonne
         « Aujourd'hui » est l'état réellement enregistré : c'est ce que les
         étudiants lisent en ce moment, et c'est ce qui change. --}}
    <div class="rounded-xl bg-amber-50 border border-amber-200/70 px-3 py-3">
        <p class="inline-flex items-start gap-2 text-sm text-amber-800">
            <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0L3.16 16.25A2 2 0 005 19z" />
            </svg>
            <span>
                <span class="font-semibold">
                    {{ $preview['copies'] }} copie(s) sur {{ $preview['finished'] }} verraient leur note ou leur barème changer.
                </span>
                Rien n'est encore écrit : les notes ci-dessous sont celles d'aujourd'hui, telles que les
                étudiants les lisent.
                @if($preview['quiet'] > 0)
                <span class="block mt-1.5">
                    {{ $preview['quiet'] }} autre(s) réponse(s) seraient renotées sans déplacer le total de leur copie.
                </span>
                @endif
                @if($quiz->quizRevealsCorrection())
                <span class="block mt-1.5">
                    Les corrections sont publiées : ces étudiants voient déjà leur note, et la nouvelle
                    s'affichera chez eux dès la confirmation.
                </span>
                @endif
            </span>
        </p>
    </div>
    @elseif($preview['answers'] > 0)
    {{-- Aucune note ne bouge, mais le recalcul écrirait quand même : deux
         réponses d'une même copie se compensent, et le verdict de chaque
         question se lit sur la copie de l'étudiant, total inchangé ou non. --}}
    <div class="rounded-xl bg-amber-50 border border-amber-200/70 px-3 py-3">
        <p class="inline-flex items-start gap-2 text-sm text-amber-800">
            <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.5 0L3.16 16.25A2 2 0 005 19z" />
            </svg>
            <span>
                <span class="font-semibold">Aucune note ne bouge.</span>
                {{ $preview['answers'] }} réponse(s) seraient renotées sans déplacer le total de leur copie :
                le verdict de ces questions change sur la copie de l'étudiant, pas la note.
            </span>
        </p>
    </div>
    @else
    <div class="rounded-xl bg-emerald-50 border border-emerald-200/70 px-3 py-3">
        <p class="inline-flex items-start gap-2 text-sm text-emerald-800">
            <svg class="h-4 w-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>
                <span class="font-semibold">Aucun changement.</span>
                Les {{ $preview['finished'] }} copie(s) rendues sont déjà notées d'après la configuration
                actuelle des questions : confirmer le recalcul ne changerait ni une note ni un barème.
            </span>
        </p>
    </div>
    @endif

    {{-- Le garde-fou du module, dit là où le clic se fait : le recalcul suit la
         configuration, il ne tranche pas à la place d'un humain. --}}
    <p class="mt-3 text-xs text-slate-500">
        Seules les notes produites par la machine sont recalculées. Une note posée par un correcteur ou par
        l'administration n'est jamais touchée — ni ses points, ni son commentaire, ni son auteur.
    </p>

    @if($preview['rows'] !== [])
    <div class="mt-6 bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Référence</th>
                        @unless($quiz->is_anonymous)
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Étudiant</th>
                        @endunless
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Aujourd'hui</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Après recalcul</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Détail</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($preview['rows'] as $row)
                    @php($attempt = $row['attempt'])
                    @php($moved = $row['from'] === null ? 0 : ($row['to'] > $row['from'] ? 1 : ($row['to'] < $row['from'] ? -1 : 0)))
                    <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm">
                            <a href="{{ route('admin.quizzes.attempts.grade', [$quiz, $attempt]) }}"
                               class="text-brand-600 hover:underline">{{ $attempt->reference }}</a>
                        </td>
                        @unless($quiz->is_anonymous)
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-slate-900">{{ $attempt->student_name ?? '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $attempt->student_email }}</div>
                        </td>
                        @endunless
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600" style="font-variant-numeric: tabular-nums">
                            @if($row['from'] === null)
                                —
                            @else
                                {{ $note($row['from']) }} <span class="text-slate-400">/ {{ $note($row['max_from']) }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold" style="font-variant-numeric: tabular-nums">
                            <span class="{{ $moved < 0 ? 'text-red-700' : ($moved > 0 ? 'text-emerald-700' : 'text-slate-900') }}">
                                {{ $note($row['to']) }}
                            </span>
                            <span class="font-normal text-slate-500">/ {{ $note($row['max_to']) }}</span>
                            @if($row['max_from'] !== null && $row['max_to'] !== $row['max_from'])
                            <span class="block text-xs font-normal text-slate-500">
                                barème : {{ $note($row['max_from']) }} → {{ $note($row['max_to']) }}
                            </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                            @if($row['answers'] > 0)
                            {{ $row['answers'] }} réponse(s) renotée(s)
                            @else
                            <span class="text-slate-400">barème seul</span>
                            @endif

                            @if($row['released'] > 0)
                            {{-- La machine ne peut plus trancher : la note redevient
                                 « en attente » et la copie repart en correction,
                                 exactement comme à la remise d'une copie. --}}
                            <span class="block mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                                    repasse en correction
                                </span>
                            </span>
                            @elseif($row['reclaimed'] > 0)
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

    @if($preview['released'] > 0 || $preview['reclaimed'] > 0)
    <p class="mt-3 text-xs text-slate-500">
        {{-- Une phrase par cas, dans l'ordre où ils se lisent. --}}
        @if($preview['released'] > 0)
        {{ $preview['released'] }} copie(s) repartiraient en correction : leur note devient provisoire pour leurs
        auteurs, comme une réponse rédigée jamais notée.
        @endif
        @if($preview['reclaimed'] > 0)
        {{ $preview['reclaimed'] }} copie(s) n'attendraient plus aucune correction une fois le recalcul passé.
        @endif
    </p>
    @endif
    @elseif($preview['finished'] > 0)
    {{-- Rien à montrer : un tableau vide ferait croire à un écran cassé. --}}
    <p class="mt-6 text-sm text-slate-500">
        Aucune note à changer. Le détail de chaque copie est sur la page des résultats.
    </p>
    @else
    <p class="mt-6 text-sm text-slate-500">
        Aucune copie rendue pour cette évaluation : il n'y a rien à recalculer.
    </p>
    @endif
</div>
@endsection

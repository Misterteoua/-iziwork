@extends('layouts.app')

@section('title', 'Journal des recalculs - ' . $quiz->title)

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.results', $quiz) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux résultats
        </a>

        <div class="mt-3">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Journal des recalculs</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $quiz->title }} · {{ $runs->count() }} passe(s) de renotation enregistrée(s)
            </p>
        </div>

        {{-- Ce que le journal contient, et ce qu'il ne contient pas. Les deux
             moitiés de la phrase comptent : un journal dont on ne sait pas ce
             qu'il omet fait douter de ce qu'il montre. --}}
        <p class="mt-3 text-xs text-slate-500">
            Chaque recalcul laisse une ligne : celui demandé depuis les résultats, la renotation qui
            accompagne une question modifiée, et la remise d'aplomb qui suit la suppression d'une question.
            Un recalcul demandé figure ici même quand il n'a rien changé — c'est une action —, alors qu'une
            question enregistrée sans effet sur les copies n'y figure pas : corriger un énoncé n'est pas un
            recalcul. Les notes posées par un correcteur ou par l'administration ne sont jamais recalculées,
            elles n'ont donc rien à faire ici.
        </p>
    </div>

    @if($runs->isEmpty())
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-8 text-center">
        <p class="text-sm font-medium text-slate-700">Aucun recalcul pour l'instant</p>
        <p class="mt-1 text-sm text-slate-500">
            Le journal se remplira au premier recalcul : une question corrigée après coup, une question
            supprimée, ou le bouton « Recalculer les notes » de la page des résultats.
        </p>
    </div>
    @else
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Quand</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Par</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Origine</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Question</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Copies renotées</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($runs as $run)
                    <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600" style="font-variant-numeric: tabular-nums">
                            {{ $run->created_at->format('d/m/Y à H:i') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium {{ $run->admin_user_id === null ? 'text-slate-500' : 'text-slate-900' }}">
                                {{ $run->actorLabel() }}
                            </div>
                            @if($run->admin?->email)
                            <div class="text-xs text-slate-500">{{ $run->admin->email }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                            {{ $run->originLabel() }}
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-600">
                            @if($run->question_label)
                                {{ \Illuminate\Support\Str::limit($run->question_label, 90) }}
                            @else
                                <span class="text-slate-400">toutes les questions</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm" style="font-variant-numeric: tabular-nums">
                            @if($run->copies_detail)
                            {{-- Le compte mène à la liste : quelles copies, et ce
                                 qu'elles valaient avant. --}}
                            <a href="{{ route('admin.quizzes.regrades.show', [$quiz, $run]) }}"
                               title="Voir les copies concernées, avec leur note avant et après"
                               class="font-semibold text-brand-600 hover:underline">{{ $run->copies }}</a>
                            <span class="text-slate-500">copie(s)</span>
                            @elseif($run->copies > 0)
                                {{-- Recalcul antérieur au détail des copies : le compte
                                     est là, la liste n'a pas été conservée. --}}
                                <span class="font-semibold text-slate-900">{{ $run->copies }}</span>
                                <span class="text-slate-500">copie(s)</span>
                            @else
                                {{-- Un recalcul qui n'a rien changé est une information :
                                     il a eu lieu, et il n'a rien trouvé à corriger. --}}
                                <span class="text-slate-500">aucune</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-3 text-xs text-slate-500">
        Le nombre de copies mène à leur liste : lesquelles, et leur note avant et après. C'est ce que
        l'aperçu montrait avant le clic, conservé tel quel — les lignes restent lisibles même après la
        suppression d'une participation.
    </p>
    @endif
</div>
@endsection

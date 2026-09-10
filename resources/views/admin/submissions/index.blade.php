@extends('layouts.app')

@section('title', 'Soumissions - ' . $form->title)

@section('content')
<div class="px-4 sm:px-0">
    {{-- Header --}}
    <div class="mb-8">
        <a href="{{ route('admin.forms.show', $form) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour au formulaire
        </a>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Soumissions</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $form->title }}</p>
            </div>
            @if($submissions->count() > 0)
            <a href="{{ route('admin.submissions.bulk', $form) }}"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-violet-600 hover:bg-violet-700 transition-colors duration-150 shrink-0">
                <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Télécharger tout (ZIP)
            </a>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $submissions->count() }} soumission{{ $submissions->count() > 1 ? 's' : '' }}
            </h2>
        </div>

        @if($submissions->isEmpty())
        <div class="p-12 text-center">
            <div class="mx-auto h-12 w-12 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-700">Aucune soumission</p>
            <p class="mt-1 text-sm text-slate-500">Les travaux déposés apparaîtront ici.</p>
        </div>
        @else
        {{-- Desktop table --}}
        <div class="hidden sm:block overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            {{ $form->is_anonymous ? 'Code' : 'Étudiant' }}
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Email</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Filière</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Fichiers</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Statut</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($submissions as $submission)
                    <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                @if(!$form->is_anonymous)
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-brand-700 text-xs font-semibold mr-3 shrink-0">
                                    {{ strtoupper(substr($submission->student_name ?? 'A', 0, 1)) }}
                                </span>
                                @endif
                                <div class="text-sm font-medium text-slate-900">
                                    {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? '-') }}
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-slate-500">{{ $submission->student_email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($submission->student_major)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-slate-100 text-xs font-medium text-slate-700">{{ $submission->student_major }}</span>
                            @else
                            <span class="text-sm text-slate-300">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-slate-600" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-slate-600" style="font-variant-numeric: tabular-nums">{{ $submission->files->count() }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $submission->status === 'validated' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $submission->status === 'validated' ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                                {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <a href="{{ route('admin.submissions.show', [$form, $submission]) }}"
                               class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg text-brand-700 bg-brand-50 hover:bg-brand-100 transition-colors duration-150">
                                Consulter
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="sm:hidden divide-y divide-slate-100">
            @foreach($submissions as $submission)
            <a href="{{ route('admin.submissions.show', [$form, $submission]) }}"
               class="block p-4 hover:bg-slate-50/60 transition-colors duration-150">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm font-medium text-slate-900 truncate">
                        {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? '-') }}
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium shrink-0 ml-2 {{ $submission->status === 'validated' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                        {{ $submission->status === 'validated' ? 'Validée' : 'Attente' }}
                    </span>
                </div>
                <p class="text-sm text-slate-500 truncate">{{ $submission->student_email }}</p>
                <p class="text-xs text-slate-400 mt-1" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }} · {{ $submission->files->count() }} fichier(s)</p>
            </a>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
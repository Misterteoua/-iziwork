@extends('layouts.app')

@section('title', $form->title)

@section('content')
<div class="px-4 sm:px-0">
    {{-- Back link --}}
    <div class="mb-6">
        <a href="{{ route('admin.forms.index') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux formulaires
        </a>
    </div>

    {{-- Header card --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 mb-8">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $form->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $form->status === 'active' ? 'bg-emerald-500' : 'bg-slate-400' }}" aria-hidden="true"></span>
                        {{ $form->status === 'active' ? 'Actif' : 'Inactif' }}
                    </span>
                    @if($form->is_anonymous)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-brand-50 text-brand-700">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Anonyme
                    </span>
                    @endif
                </div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 text-balance break-words">{{ $form->title }}</h1>
                @if($form->description)
                <p class="mt-2 text-sm sm:text-base text-slate-500 max-w-2xl">{{ $form->description }}</p>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex flex-wrap gap-2 lg:justify-end lg:shrink-0">
                <button type="button"
                        data-copy="{{ route('submit.form', $form->token) }}"
                        onclick="copyText(this.dataset.copy, this)"
                        class="inline-flex items-center px-3.5 py-2 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150"
                        aria-label="Copier le lien de soumission">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    <span class="hidden sm:inline" data-copy-label>Copier le lien</span>
                    <span class="sm:hidden" data-copy-label>Lien</span>
                </button>
                <form method="POST" action="{{ route('admin.forms.toggle', $form) }}" class="inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="inline-flex items-center px-3.5 py-2 border border-transparent text-sm font-semibold rounded-xl text-white {{ $form->status === 'active' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-emerald-600 hover:bg-emerald-700' }} transition-colors duration-150">
                        {{ $form->status === 'active' ? 'Désactiver' : 'Activer' }}
                    </button>
                </form>
                <a href="{{ route('admin.forms.edit', $form) }}"
                   class="inline-flex items-center px-3.5 py-2 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Modifier
                </a>
                @if($form->submissions->count() > 0)
                <a href="{{ route('admin.submissions.bulk', $form) }}"
                   class="inline-flex items-center px-3.5 py-2 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Tout télécharger
                </a>
                @endif
                <form method="POST" action="{{ route('admin.forms.destroy', $form) }}" class="inline"
                      onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce formulaire ? Cette action est irréversible.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center px-3.5 py-2 border border-transparent text-sm font-semibold rounded-xl text-red-600 bg-red-50 hover:bg-red-100 transition-colors duration-150"
                            aria-label="Supprimer le formulaire">
                        <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Supprimer
                    </button>
                </form>
            </div>
        </div>

        {{-- Stats --}}
        <div class="mt-8 grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-4">
                <p class="text-xs font-medium text-slate-500">Soumissions</p>
                <p class="text-xl sm:text-2xl font-bold text-slate-900 mt-1" style="font-variant-numeric: tabular-nums">{{ $form->submissions_count ?? $form->submissions->count() }}</p>
            </div>
            <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-4">
                <p class="text-xs font-medium text-slate-500">Champs</p>
                <p class="text-xl sm:text-2xl font-bold text-slate-900 mt-1" style="font-variant-numeric: tabular-nums">{{ $form->fields->count() }}</p>
            </div>
            <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-4">
                <p class="text-xs font-medium text-slate-500">Max dépôts</p>
                <p class="text-xl sm:text-2xl font-bold text-slate-900 mt-1">{{ $form->max_submissions ?? '∞' }}</p>
            </div>
            <div class="bg-slate-50/80 rounded-xl border border-slate-100 p-4">
                <p class="text-xs font-medium text-slate-500">Ouverture</p>
                <p class="text-sm sm:text-base font-semibold text-slate-900 mt-1" style="font-variant-numeric: tabular-nums">{{ $form->open_date ? $form->open_date->format('d/m/Y H:i') : 'Immédiat' }}</p>
            </div>
        </div>
    </div>

    {{-- Lien court : plus facile à dicter en classe ou à écrire au tableau. Le
         lien complet ci-dessus continue de fonctionner, inchangé. --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 px-6 py-4 mb-8 flex flex-col sm:flex-row sm:items-center gap-3">
        <p class="text-sm font-medium text-slate-700 shrink-0">Lien court</p>
        <code class="text-sm font-mono text-slate-600 break-all">{{ $shortLink->url() }}</code>
        <button type="button" data-copy="{{ $shortLink->url() }}" onclick="copyText(this.dataset.copy, this)"
                class="sm:ml-auto inline-flex items-center justify-center px-3.5 py-2 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150 shrink-0">
            <span data-copy-label>Copier le lien court</span>
        </button>
    </div>

    {{-- Submissions section --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h2 class="text-base font-semibold text-slate-900">Soumissions <span class="text-slate-400 font-normal">({{ $form->submissions->count() }})</span></h2>
        </div>

        @if($form->submissions->isEmpty())
        <div class="p-12 text-center">
            <div class="mx-auto h-12 w-12 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-700">Aucune soumission pour le moment</p>
            <p class="mt-1 text-sm text-slate-500">Partagez le lien du formulaire pour recevoir des travaux.</p>
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
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($form->submissions as $submission)
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
            @foreach($form->submissions as $submission)
            <a href="{{ route('admin.submissions.show', [$form, $submission]) }}"
               class="block p-4 hover:bg-slate-50/60 transition-colors duration-150">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm font-medium text-slate-900 truncate">
                        {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? '-') }}
                    </span>
                    <span class="text-xs text-slate-400 shrink-0 ml-2" style="font-variant-numeric: tabular-nums">{{ $submission->files->count() }} fichier(s)</span>
                </div>
                <p class="text-sm text-slate-500 truncate">{{ $submission->student_email }}</p>
                <p class="text-xs text-slate-400 mt-1" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</p>
            </a>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection


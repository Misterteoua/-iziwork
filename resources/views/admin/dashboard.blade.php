@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
<div class="px-4 sm:px-0">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Tableau de bord</h1>
            <p class="mt-1 text-sm text-slate-500">Vue d'ensemble de vos formulaires et soumissions</p>
        </div>
        <a href="{{ route('admin.forms.create') }}"
           class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover transition-all duration-150 shrink-0">
            <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nouveau formulaire
        </a>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 mb-8">
        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-6 hover:shadow-card-hover transition-shadow duration-200">
            <div class="flex items-center min-w-0">
                <div class="p-3 rounded-xl bg-brand-50 shrink-0">
                    <svg class="h-6 w-6 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div class="ml-4 min-w-0">
                    <p class="text-sm font-medium text-slate-500 truncate">Total formulaires</p>
                    <p class="text-2xl font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $stats['total_forms'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-6 hover:shadow-card-hover transition-shadow duration-200">
            <div class="flex items-center min-w-0">
                <div class="p-3 rounded-xl bg-emerald-50 shrink-0">
                    <svg class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-4 min-w-0">
                    <p class="text-sm font-medium text-slate-500 truncate">Formulaires actifs</p>
                    <p class="text-2xl font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $stats['active_forms'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-6 hover:shadow-card-hover transition-shadow duration-200 sm:col-span-2 lg:col-span-1">
            <div class="flex items-center min-w-0">
                <div class="p-3 rounded-xl bg-brand-100 shrink-0">
                    <svg class="h-6 w-6 text-brand-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="ml-4 min-w-0">
                    <p class="text-sm font-medium text-slate-500 truncate">Total soumissions</p>
                    <p class="text-2xl font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $stats['total_submissions'] }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.dashboard') }}"
          role="search" aria-label="Filtrer les soumissions"
          class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-5 sm:p-6 mb-8">
        <div class="flex items-center gap-3 mb-5">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 shrink-0">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v1.586a1 1 0 01-.293.707l-6.414 6.414A1 1 0 0014 14.414V19a1 1 0 01-1.447.894l-2-1A1 1 0 0110 18v-3.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                </svg>
            </span>
            <div>
                <h2 class="text-base font-semibold text-slate-900">Filtrer les soumissions</h2>
                <p class="text-xs text-slate-500">Par formulaire, période, plage de dates ou statut</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div>
                <label for="filter-form" class="block text-sm font-medium text-slate-700 mb-1.5">Formulaire</label>
                <select name="form" id="filter-form"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    <option value="">Tous les formulaires</option>
                    @foreach($forms as $formOption)
                    <option value="{{ $formOption->id }}" @selected($filters['form_id'] === $formOption->id)>{{ $formOption->title }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="filter-period" class="block text-sm font-medium text-slate-700 mb-1.5">Période</label>
                <select name="period" id="filter-period"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    <option value="all" @selected($filters['period'] === 'all')>Toutes les dates</option>
                    <option value="today" @selected($filters['period'] === 'today')>Aujourd'hui</option>
                    <option value="7d" @selected($filters['period'] === '7d')>7 derniers jours</option>
                    <option value="30d" @selected($filters['period'] === '30d')>30 derniers jours</option>
                    <option value="month" @selected($filters['period'] === 'month')>Ce mois-ci</option>
                </select>
            </div>

            <div>
                <label for="filter-from" class="block text-sm font-medium text-slate-700 mb-1.5">Du</label>
                <input type="date" name="from" id="filter-from" value="{{ $filters['from'] }}"
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
            </div>

            <div>
                <label for="filter-to" class="block text-sm font-medium text-slate-700 mb-1.5">Au</label>
                <input type="date" name="to" id="filter-to" value="{{ $filters['to'] }}"
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
            </div>

            <div>
                <label for="filter-status" class="block text-sm font-medium text-slate-700 mb-1.5">Statut</label>
                <select name="status" id="filter-status"
                        class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    <option value="">Tous les statuts</option>
                    <option value="validated" @selected($filters['status'] === 'validated')>Validée</option>
                    <option value="pending" @selected($filters['status'] === 'pending')>En attente</option>
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mt-5">
            <button type="submit"
                    class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Filtrer
            </button>
            @if($isFiltered)
            <a href="{{ route('admin.dashboard') }}"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                Réinitialiser
            </a>
            @endif
            <a href="{{ $exportUrl }}"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150 sm:ml-auto">
                <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Exporter en CSV
            </a>
        </div>

        <p class="mt-3 text-xs text-slate-400">
            Si vous renseignez « Du » ou « Au », cette plage remplace la période choisie.
            L'export CSV reprend les filtres actifs, sans la limite d'affichage.
        </p>
    </form>

    {{-- Recent Submissions --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900">Soumissions récentes</h2>
            <p class="text-xs text-slate-500">
                @php($shown = $stats['recent_submissions']->count())
                @if($isFiltered)
                    {{ $shown }} affichée{{ $shown > 1 ? 's' : '' }} sur {{ $filteredCount }} correspondante{{ $filteredCount > 1 ? 's' : '' }}
                @else
                    {{ $shown }} plus récente{{ $shown > 1 ? 's' : '' }} sur {{ $stats['total_submissions'] }} au total
                @endif
            </p>
        </div>

        @if($stats['recent_submissions']->isEmpty())
        <div class="p-12 text-center">
            <div class="mx-auto h-12 w-12 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            @if($isFiltered)
            <p class="text-sm font-medium text-slate-700">Aucune soumission ne correspond à ces filtres</p>
            <p class="mt-1 text-sm text-slate-500">Élargissez la période, choisissez un autre formulaire ou vérifiez le statut.</p>
            <a href="{{ route('admin.dashboard') }}"
               class="mt-5 inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                Réinitialiser les filtres
            </a>
            @else
            <p class="text-sm font-medium text-slate-700">Aucune soumission</p>
            <p class="mt-1 text-sm text-slate-500">Les soumissions apparaîtront ici dès que des étudiants enverront leurs travaux.</p>
            @endif
        </div>
        @else
        {{-- Desktop table --}}
        <div class="hidden sm:block overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Étudiant</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Formulaire</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Statut</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    @foreach($stats['recent_submissions'] as $submission)
                    <tr class="hover:bg-slate-50/60 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-brand-700 text-xs font-semibold mr-3 shrink-0">
                                    {{ strtoupper(substr($submission->student_name ?? 'A', 0, 1)) }}
                                </span>
                                <div>
                                    <div class="text-sm font-medium text-slate-900">{{ $submission->student_name ?? 'Anonyme' }}</div>
                                    <div class="text-xs text-slate-500">{{ $submission->student_email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-slate-100 text-xs font-medium text-slate-700">
                                {{ $submission->form->title }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-slate-600" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $submission->status === 'validated' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $submission->status === 'validated' ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                                {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="sm:hidden divide-y divide-slate-100">
            @foreach($stats['recent_submissions'] as $submission)
            <div class="p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-slate-900">{{ $submission->student_name ?? 'Anonyme' }}</span>
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium {{ $submission->status === 'validated' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $submission->status === 'validated' ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                        {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                    </span>
                </div>
                <p class="text-sm text-slate-500">{{ $submission->student_email }}</p>
                <p class="text-xs text-slate-400">{{ $submission->form->title }} · <span style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</span></p>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
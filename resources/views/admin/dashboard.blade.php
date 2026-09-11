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

    {{-- Recent Submissions --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100">
            <h2 class="text-base font-semibold text-slate-900">Soumissions récentes</h2>
        </div>

        @if($stats['recent_submissions']->isEmpty())
        <div class="p-12 text-center">
            <div class="mx-auto h-12 w-12 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-700">Aucune soumission</p>
            <p class="mt-1 text-sm text-slate-500">Les soumissions apparaîtront ici dès que des étudiants enverront leurs travaux.</p>
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
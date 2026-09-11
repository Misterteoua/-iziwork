@extends('layouts.app')

@section('title', 'Formulaires')

@section('content')
<div class="px-4 sm:px-0">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Formulaires</h1>
            <p class="mt-1 text-sm text-slate-500">Créez et gérez vos formulaires de dépôt</p>
        </div>
        <a href="{{ route('admin.forms.create') }}"
           class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover transition-all duration-150 shrink-0">
            <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nouveau formulaire
        </a>
    </div>

    @if($forms->isEmpty())
    {{-- Empty state --}}
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-12 text-center max-w-lg mx-auto">
        <div class="mx-auto h-16 w-16 rounded-2xl bg-brand-50 flex items-center justify-center mb-4">
            <svg class="h-8 w-8 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        </div>
        <h3 class="text-base font-semibold text-slate-900">Aucun formulaire</h3>
        <p class="mt-1.5 text-sm text-slate-500">Commencez par créer votre premier formulaire de dépôt.</p>
        <div class="mt-6">
            <a href="{{ route('admin.forms.create') }}"
               class="inline-flex items-center px-4 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 transition-all duration-150">
                <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Créer un formulaire
            </a>
        </div>
    </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
        @foreach($forms as $form)
        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 hover:shadow-card-hover transition-all duration-200 flex flex-col overflow-hidden group">
            <div class="p-6 flex-1">
                {{-- Header row --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base font-semibold text-slate-900 group-hover:text-brand-700 transition-colors duration-150">
                            <a href="{{ route('admin.forms.show', $form) }}" class="focus-visible:ring-2 focus-visible:ring-brand-500 rounded">
                                <span class="line-clamp-1">{{ $form->title }}</span>
                            </a>
                        </h3>
                        @if($form->description)
                        <p class="mt-1 text-sm text-slate-500 line-clamp-2">{{ $form->description }}</p>
                        @endif
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium shrink-0 {{ $form->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $form->status === 'active' ? 'bg-emerald-500' : 'bg-slate-400' }}" aria-hidden="true"></span>
                        {{ $form->status === 'active' ? 'Actif' : 'Inactif' }}
                    </span>
                </div>

                {{-- Meta --}}
                <div class="mt-5 space-y-2.5">
                    <div class="flex items-center text-sm text-slate-600">
                        <svg class="h-4 w-4 mr-2.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <span style="font-variant-numeric: tabular-nums">{{ $form->submissions_count }}</span>
                        <span class="ml-1 text-slate-400">soumission{{ $form->submissions_count > 1 ? 's' : '' }}</span>
                    </div>

                    @if($form->open_date)
                    <div class="flex items-center text-sm text-slate-600">
                        <svg class="h-4 w-4 mr-2.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <span>Ouvre le <span class="font-medium text-slate-700" style="font-variant-numeric: tabular-nums">{{ $form->open_date->format('d/m/Y H:i') }}</span></span>
                    </div>
                    @endif

                    @if($form->max_submissions)
                    <div class="flex items-center text-sm text-slate-600">
                        <svg class="h-4 w-4 mr-2.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <span>Max <span class="font-medium text-slate-700">{{ $form->max_submissions }}</span> dépôt(s)</span>
                    </div>
                    @endif

                    @if($form->is_anonymous)
                    <div class="flex items-center text-sm text-brand-600">
                        <svg class="h-4 w-4 mr-2.5 text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span>Soumissions anonymes</span>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Footer actions --}}
            <div class="px-6 py-4 bg-slate-50/60 border-t border-slate-100 flex gap-2">
                <a href="{{ route('admin.forms.show', $form) }}"
                   class="flex-1 text-center px-3 py-2 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150">
                    Consulter
                </a>
                <a href="{{ route('admin.forms.edit', $form) }}"
                   class="flex-1 text-center px-3 py-2 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150">
                    Modifier
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
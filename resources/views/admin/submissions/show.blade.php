@extends('layouts.app')

@section('title', 'Soumission - ' . ($submission->student_name ?? 'Anonyme'))

@section('content')
<div class="px-4 sm:px-0 max-w-3xl">
    {{-- Header --}}
    <div class="mb-8">
        <a href="{{ route('admin.submissions.index', $form) }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux soumissions
        </a>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 mt-3 text-balance">
            Soumission de {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? 'Anonyme') }}
        </h1>
        <div class="mt-2">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $submission->status === 'validated' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $submission->status === 'validated' ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
                {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
            </span>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Info card --}}
        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <div class="flex items-center gap-3 mb-6">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 shrink-0">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Informations</h2>
                    <p class="text-xs text-slate-500">Détails du dépôt de l'étudiant</p>
                </div>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                @if(!$form->is_anonymous && $submission->student_name)
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Nom</dt>
                    <dd class="text-sm font-medium text-slate-900 break-words">{{ $submission->student_name }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Email</dt>
                    <dd class="text-sm font-medium text-slate-900 break-all">{{ $submission->student_email }}</dd>
                </div>
                @if($submission->student_phone)
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Téléphone</dt>
                    <dd class="text-sm font-medium text-slate-900" style="font-variant-numeric: tabular-nums">{{ $submission->student_phone }}</dd>
                </div>
                @endif
                @if($submission->student_major)
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Filière</dt>
                    <dd class="text-sm font-medium text-slate-900 break-words">{{ $submission->student_major }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Date de soumission</dt>
                    <dd class="text-sm font-medium text-slate-900" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y à H:i') }}</dd>
                </div>
                @if($submission->anonymous_code)
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Code anonyme</dt>
                    <dd class="text-sm font-semibold text-violet-700">{{ $submission->anonymous_code }}</dd>
                </div>
                @endif
                @if($submission->ip_address)
                <div>
                    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wider mb-1">Adresse IP</dt>
                    <dd class="text-sm font-medium text-slate-900" style="font-variant-numeric: tabular-nums">{{ $submission->ip_address }}</dd>
                </div>
                @endif
            </dl>
        </section>

        {{-- Files card --}}
        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <div class="flex items-center gap-3 mb-6">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 shrink-0">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Fichiers <span class="text-slate-400 font-normal">({{ $submission->files->count() }})</span></h2>
                    <p class="text-xs text-slate-500">Documents déposés par l'étudiant</p>
                </div>
                @if(!$submission->files->isEmpty())
                <a href="{{ route('admin.submissions.download.submission', ['form' => $form, 'submission' => $submission]) }}"
                   class="ml-auto inline-flex items-center justify-center px-3.5 py-2 border border-transparent text-sm font-semibold rounded-xl text-white bg-violet-600 hover:bg-violet-700 transition-colors duration-150 shrink-0">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Télécharger tout (ZIP)
                </a>
                @endif
            </div>

            @if($submission->files->isEmpty())
            <p class="text-sm text-slate-500 bg-slate-50/60 rounded-xl p-6 text-center">Aucun fichier déposé.</p>
            @else
            <ul class="space-y-3">
                @foreach($submission->files as $file)
                <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-4 bg-slate-50/60 rounded-xl border border-slate-100 gap-3">
                    <div class="flex items-center min-w-0">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-400 mr-3 shrink-0">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $file->original_name }}</p>
                            <p class="text-xs text-slate-500">{{ $file->formatted_size }} · {{ $file->mime_type }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.submissions.download', $file) }}"
                       class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150 shrink-0"
                       aria-label="Télécharger {{ $file->original_name }}">
                        <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Télécharger
                    </a>
                </li>
                @endforeach
            </ul>
            @endif
        </section>
    </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Soumission - ' . ($submission->student_name ?? 'Anonyme'))

@section('content')
<div class="px-4 sm:px-0 max-w-3xl">
    <div class="mb-6">
        <a href="{{ route('admin.submissions.index', $form) }}" class="text-sm text-indigo-600 hover:text-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
            ← Retour aux soumissions
        </a>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mt-2 text-balance">
            Soumission de {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? 'Anonyme') }}
        </h1>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Informations</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                @if(!$form->is_anonymous && $submission->student_name)
                <div>
                    <dt class="text-sm text-gray-500">Nom</dt>
                    <dd class="text-sm font-medium text-gray-900 break-words">{{ $submission->student_name }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-sm text-gray-500">Email</dt>
                    <dd class="text-sm font-medium text-gray-900 break-all">{{ $submission->student_email }}</dd>
                </div>
                @if($submission->student_phone)
                <div>
                    <dt class="text-sm text-gray-500">Téléphone</dt>
                    <dd class="text-sm font-medium text-gray-900" style="font-variant-numeric: tabular-nums">{{ $submission->student_phone }}</dd>
                </div>
                @endif
                @if($submission->student_major)
                <div>
                    <dt class="text-sm text-gray-500">Filière</dt>
                    <dd class="text-sm font-medium text-gray-900 break-words">{{ $submission->student_major }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-sm text-gray-500">Date de soumission</dt>
                    <dd class="text-sm font-medium text-gray-900" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y à H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Statut</dt>
                    <dd>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $submission->status === 'validated' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                            {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                        </span>
                    </dd>
                </div>
                @if($submission->anonymous_code)
                <div>
                    <dt class="text-sm text-gray-500">Code anonyme</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ $submission->anonymous_code }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-sm text-gray-500">Adresse IP</dt>
                    <dd class="text-sm font-medium text-gray-900" style="font-variant-numeric: tabular-nums">{{ $submission->ip_address ?? '-' }}</dd>
                </div>
            </dl>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Fichiers ({{ $submission->files->count() }})</h2>
            
            @if($submission->files->isEmpty())
            <p class="text-gray-500 text-sm">Aucun fichier déposé.</p>
            @else
            <ul class="space-y-3">
                @foreach($submission->files as $file)
                <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-4 bg-gray-50 rounded-lg gap-3">
                    <div class="flex items-center min-w-0">
                        <svg class="h-8 w-8 text-gray-400 mr-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $file->original_name }}</p>
                            <p class="text-xs text-gray-500">{{ $file->formatted_size }} · {{ $file->mime_type }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.submissions.download', $file) }}" 
                       class="inline-flex items-center justify-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 transition-colors duration-200 shrink-0"
                       aria-label="Télécharger {{ $file->original_name }}">
                        <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Télécharger
                    </a>
                </li>
                @endforeach
            </ul>
            @endif
        </div>
    </div>
</div>
@endsection

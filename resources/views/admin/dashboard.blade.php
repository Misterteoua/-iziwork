@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
<div class="px-4 sm:px-0">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Tableau de bord</h1>
    
    {{-- Stats cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div class="flex items-center min-w-0">
                <div class="p-3 rounded-full bg-indigo-100 shrink-0">
                    <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div class="ml-4 min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Total formulaires</p>
                    <p class="text-2xl font-semibold text-gray-900" style="font-variant-numeric: tabular-nums">{{ $stats['total_forms'] }}</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div class="flex items-center min-w-0">
                <div class="p-3 rounded-full bg-green-100 shrink-0">
                    <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-4 min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Formulaires actifs</p>
                    <p class="text-2xl font-semibold text-gray-900" style="font-variant-numeric: tabular-nums">{{ $stats['active_forms'] }}</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 sm:col-span-2 lg:col-span-1">
            <div class="flex items-center min-w-0">
                <div class="p-3 rounded-full bg-purple-100 shrink-0">
                    <svg class="h-6 w-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="ml-4 min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Total soumissions</p>
                    <p class="text-2xl font-semibold text-gray-900" style="font-variant-numeric: tabular-nums">{{ $stats['total_submissions'] }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Submissions --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Soumissions récentes</h2>
        </div>
        
        @if($stats['recent_submissions']->isEmpty())
        <div class="p-12 text-center text-gray-500">
            <svg class="mx-auto h-12 w-12 text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <p>Aucune soumission pour le moment.</p>
        </div>
        @else
        {{-- Desktop table --}}
        <div class="hidden sm:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Formulaire</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($stats['recent_submissions'] as $submission)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $submission->student_name ?? 'Anonyme' }}</div>
                            <div class="text-sm text-gray-500">{{ $submission->student_email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ $submission->form->title }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $submission->status === 'validated' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="sm:hidden divide-y divide-gray-100">
            @foreach($stats['recent_submissions'] as $submission)
            <div class="p-4 space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-900">{{ $submission->student_name ?? 'Anonyme' }}</span>
                    <span class="px-2 text-xs font-semibold rounded-full {{ $submission->status === 'validated' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                    </span>
                </div>
                <p class="text-sm text-gray-500">{{ $submission->student_email }}</p>
                <p class="text-xs text-gray-400">{{ $submission->form->title }} · {{ $submission->created_at->format('d/m/Y H:i') }}</p>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Quick Action --}}
    <div class="mt-8">
        <a href="{{ route('admin.forms.create') }}" 
           class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white gradient-bg hover:opacity-90 transition-opacity duration-200">
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nouveau formulaire
        </a>
    </div>
</div>
@endsection

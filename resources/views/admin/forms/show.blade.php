@extends('layouts.app')

@section('title', $form->title)

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-6">
        <a href="{{ route('admin.forms.index') }}" class="text-sm text-indigo-600 hover:text-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
            ← Retour aux formulaires
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 sm:p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3">
            <div class="min-w-0">
                <h1 class="text-xl sm:text-2xl font-bold text-gray-900 text-balance break-words">{{ $form->title }}</h1>
                @if($form->description)
                <p class="mt-2 text-gray-500 text-sm sm:text-base">{{ $form->description }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="px-3 py-1 text-xs sm:text-sm font-medium rounded-full {{ $form->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                    {{ $form->status === 'active' ? 'Actif' : 'Inactif' }}
                </span>
                @if($form->is_anonymous)
                <span class="px-3 py-1 text-xs sm:text-sm font-medium rounded-full bg-purple-100 text-purple-800">
                    Anonyme
                </span>
                @endif
            </div>
        </div>

        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="bg-gray-50 rounded-lg p-3 sm:p-4">
                <p class="text-xs sm:text-sm text-gray-500">Soumissions</p>
                <p class="text-xl sm:text-2xl font-bold text-gray-900" style="font-variant-numeric: tabular-nums">{{ $form->submissions_count ?? $form->submissions->count() }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 sm:p-4">
                <p class="text-xs sm:text-sm text-gray-500">Champs</p>
                <p class="text-xl sm:text-2xl font-bold text-gray-900" style="font-variant-numeric: tabular-nums">{{ $form->fields->count() }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 sm:p-4">
                <p class="text-xs sm:text-sm text-gray-500">Max dépôts</p>
                <p class="text-xl sm:text-2xl font-bold text-gray-900">{{ $form->max_submissions ?? 'Illimité' }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 sm:p-4">
                <p class="text-xs sm:text-sm text-gray-500">Ouverture</p>
                <p class="text-sm sm:text-base font-semibold text-gray-900">{{ $form->open_date ? $form->open_date->format('d/m/Y H:i') : 'Immédiat' }}</p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2 sm:gap-3">
            <button onclick="copyLink()" 
                    class="inline-flex items-center px-3 sm:px-4 py-2 border border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 transition-colors duration-200"
                    aria-label="Copier le lien de soumission">
                <svg class="h-5 w-5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
                <span class="hidden sm:inline">Copier le lien</span>
                <span class="sm:hidden">Lien</span>
            </button>
            <form method="POST" action="{{ route('admin.forms.toggle', $form) }}" class="inline">
                @csrf
                @method('PATCH')
                <button type="submit" 
                        class="inline-flex items-center px-3 sm:px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white {{ $form->status === 'active' ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 transition-colors duration-200">
                    {{ $form->status === 'active' ? 'Désactiver' : 'Activer' }}
                </button>
            </form>
            <a href="{{ route('admin.forms.edit', $form) }}" 
               class="inline-flex items-center px-3 sm:px-4 py-2 border border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 transition-colors duration-200">
                Modifier
            </a>
            @if($form->submissions->count() > 0)
            <a href="{{ route('admin.submissions.bulk', $form) }}" 
               class="inline-flex items-center px-3 sm:px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-purple-500 transition-colors duration-200">
                <svg class="h-5 w-5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Tout (ZIP)
            </a>
            @endif
            <form method="POST" action="{{ route('admin.forms.destroy', $form) }}" class="inline"
                  onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce formulaire ? Cette action est irréversible.')">
                @csrf
                @method('DELETE')
                <button type="submit" 
                        class="inline-flex items-center px-3 sm:px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-red-600 hover:bg-red-700 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-red-500 transition-colors duration-200"
                        aria-label="Supprimer le formulaire">
                    Supprimer
                </button>
            </form>
        </div>
    </div>

    {{-- Submissions --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Soumissions ({{ $form->submissions->count() }})</h2>
        </div>

        @if($form->submissions->isEmpty())
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
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ $form->is_anonymous ? 'Code' : 'Étudiant' }}
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filière</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fichiers</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($form->submissions as $submission)
                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">
                                {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? '-') }}
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">{{ $submission->student_email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">{{ $submission->student_major ?? '-' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">{{ $submission->files->count() }} fichier(s)</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a href="{{ route('admin.submissions.show', [$form, $submission]) }}" 
                               class="text-indigo-600 hover:text-indigo-900 focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
                                Voir
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="sm:hidden divide-y divide-gray-100">
            @foreach($form->submissions as $submission)
            <a href="{{ route('admin.submissions.show', [$form, $submission]) }}" 
               class="block p-4 hover:bg-gray-50 transition-colors duration-150">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium text-gray-900 truncate">
                        {{ $form->is_anonymous ? $submission->anonymous_code : ($submission->student_name ?? '-') }}
                    </span>
                    <span class="text-xs text-gray-400 shrink-0 ml-2">{{ $submission->files->count() }} ficher(s)</span>
                </div>
                <p class="text-sm text-gray-500 truncate">{{ $submission->student_email }}</p>
                <p class="text-xs text-gray-400 mt-1" style="font-variant-numeric: tabular-nums">{{ $submission->created_at->format('d/m/Y H:i') }}</p>
            </a>
            @endforeach
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    function copyLink() {
        const link = '{{ route("submit.form", $form->token) }}';
        navigator.clipboard.writeText(link).then(() => {
            alert('Lien copié dans le presse-papier !\n\n' + link);
        });
    }
</script>
@endsection

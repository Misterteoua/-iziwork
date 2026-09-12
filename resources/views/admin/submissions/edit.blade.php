@extends('layouts.app')

@section('title', 'Modifier la soumission - ' . ($submission->student_name ?? 'Anonyme'))

@section('content')
<div class="px-4 sm:px-0 max-w-3xl">
    {{-- Header --}}
    <div class="mb-8">
        <a href="{{ route('admin.submissions.show', ['form' => $form, 'submission' => $submission]) }}"
           class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour à la soumission
        </a>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 mt-3">Modifier la soumission</h1>
        <p class="mt-1 text-sm text-slate-500">
            L'adresse IP et la date de soumission ne sont pas modifiables (traçabilité).
        </p>
    </div>

    <form method="POST" action="{{ route('admin.submissions.update', ['form' => $form, 'submission' => $submission]) }}" class="space-y-6" novalidate>
        @csrf
        @method('PUT')

        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
            <h2 class="text-base font-semibold text-slate-900 mb-6">Informations de l'étudiant</h2>

            <div class="grid grid-cols-1 gap-5">
                <div>
                    <label for="student_name" class="block text-sm font-medium text-slate-700 mb-1.5">Nom complet</label>
                    <input id="student_name" name="student_name" type="text" value="{{ old('student_name', $submission->student_name) }}"
                           class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('student_name') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}">
                    @error('student_name')
                    <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="student_email" class="block text-sm font-medium text-slate-700 mb-1.5">Adresse email</label>
                    <input id="student_email" name="student_email" type="email" value="{{ old('student_email', $submission->student_email) }}"
                           class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('student_email') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}"
                           placeholder="Peut être vidée si l'email saisi par l'étudiant est erroné">
                    @error('student_email')
                    <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="student_phone" class="block text-sm font-medium text-slate-700 mb-1.5">Téléphone</label>
                        <input id="student_phone" name="student_phone" type="tel" value="{{ old('student_phone', $submission->student_phone) }}"
                               class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('student_phone') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}">
                        @error('student_phone')
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="student_major" class="block text-sm font-medium text-slate-700 mb-1.5">Filière</label>
                        <input id="student_major" name="student_major" type="text" value="{{ old('student_major', $submission->student_major) }}"
                               class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('student_major') ? 'border-red-300 bg-red-50/30' : 'border-slate-300' }}">
                        @error('student_major')
                        <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-slate-700 mb-1.5">Statut</label>
                    <select id="status" name="status"
                            class="w-full px-4 py-2.5 border rounded-xl text-sm text-slate-900 bg-white focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150 {{ $errors->has('status') ? 'border-red-300' : 'border-slate-300' }}"
                            style="background-color: white;">
                        <option value="pending" {{ old('status', $submission->status) === 'pending' ? 'selected' : '' }}>En attente</option>
                        <option value="validated" {{ old('status', $submission->status) === 'validated' ? 'selected' : '' }}>Validée</option>
                    </select>
                    @error('status')
                    <p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <div class="flex flex-col-reverse sm:flex-row justify-between gap-3">
            <a href="{{ route('admin.submissions.show', ['form' => $form, 'submission' => $submission]) }}"
               class="px-5 py-2.5 border border-slate-200 text-sm font-medium rounded-xl text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150 text-center">
                Annuler
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-6 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 hover:shadow-card-hover focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand-500 transition-all duration-150">
                Enregistrer les modifications
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </button>
        </div>
    </form>

    {{-- Attachments management --}}
    <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 mt-8">
        <h2 class="text-base font-semibold text-slate-900 mb-1">Pièces jointes <span class="text-slate-400 font-normal">({{ $submission->files->count() }})</span></h2>
        <p class="text-xs text-slate-500 mb-6">Remplacez un fichier erroné, ajoutez une pièce manquante ou supprimez un document. PDF, Word, PowerPoint, ZIP — max 5 Mo.</p>

        @if($submission->files->isNotEmpty())
        <ul class="space-y-3 mb-6">
            @foreach($submission->files as $file)
            <li class="p-4 bg-slate-50/60 rounded-xl border border-slate-100">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center min-w-0">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white border border-slate-200 text-slate-400 mr-3 shrink-0">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $file->original_name }}</p>
                            <p class="text-xs text-slate-500">{{ $file->formatted_size }}@if($file->field_label) · {{ $file->field_label }}@endif</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        {{-- Replace --}}
                        <form method="POST" action="{{ route('admin.submissions.files.replace', ['form' => $form, 'submission' => $submission, 'file' => $file]) }}"
                              enctype="multipart/form-data" class="flex items-center gap-2">
                            @csrf
                            @method('PUT')
                            <input type="file" name="file" accept=".pdf,.docx,.pptx,.zip" required
                                   class="text-xs text-slate-500 file:mr-2 file:px-3 file:py-1.5 file:text-xs file:font-medium file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 file:cursor-pointer"
                                   aria-label="Remplacer {{ $file->original_name }}">
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-1.5 border border-slate-200 text-xs font-medium rounded-lg text-slate-700 bg-white hover:bg-slate-50 hover:border-slate-300 transition-colors duration-150">
                                Remplacer
                            </button>
                        </form>
                        {{-- Delete --}}
                        <form method="POST" action="{{ route('admin.submissions.files.destroy', ['form' => $form, 'submission' => $submission, 'file' => $file]) }}"
                              onsubmit="return confirm('Supprimer définitivement ce fichier ?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-1.5 border border-red-200 text-xs font-medium rounded-lg text-red-600 bg-white hover:bg-red-50 transition-colors duration-150">
                                Supprimer
                            </button>
                        </form>
                    </div>
                </div>
            </li>
            @endforeach
        </ul>
        @else
        <p class="text-sm text-slate-500 bg-slate-50/60 rounded-xl p-4 text-center mb-6">Aucun fichier déposé.</p>
        @endif

        {{-- Add a new file --}}
        <form method="POST" action="{{ route('admin.submissions.files.store', ['form' => $form, 'submission' => $submission]) }}"
              enctype="multipart/form-data" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 pt-5 border-t border-slate-100">
            @csrf
            <input type="file" name="file" accept=".pdf,.docx,.pptx,.zip" required
                   class="flex-1 text-sm text-slate-500 file:mr-3 file:px-4 file:py-2 file:text-sm file:font-medium file:rounded-xl file:border-0 file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 file:cursor-pointer"
                   aria-label="Ajouter un fichier">
            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Ajouter
            </button>
        </form>
        @error('file')
        <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
    </section>

    {{-- Danger zone --}}
    <section class="mt-8 rounded-2xl border border-red-200 bg-red-50/40 p-6 sm:p-8">
        <h2 class="text-base font-semibold text-red-800 mb-1">Zone de danger</h2>
        <p class="text-xs text-red-600/80 mb-5">La suppression est définitive : la soumission, ses fichiers en base et les documents stockés seront effacés. L'étudiant pourra se réinscrire avec la même adresse.</p>

        <form method="POST" action="{{ route('admin.submissions.destroy', ['form' => $form, 'submission' => $submission]) }}"
              onsubmit="return confirm('Supprimer définitivement cette soumission et tous ses fichiers ? Cette action est irréversible.');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 border border-red-300 text-sm font-semibold rounded-xl text-red-700 bg-white hover:bg-red-50 transition-colors duration-150">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Supprimer cette soumission
            </button>
        </form>
    </section>
</div>
@endsection

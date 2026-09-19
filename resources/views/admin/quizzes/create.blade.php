@extends('layouts.app')

@section('title', 'Nouvelle évaluation')

@section('content')
<div class="px-4 sm:px-0 max-w-3xl">
    <div class="mb-8">
        <a href="{{ route('admin.quizzes.index') }}" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour aux évaluations
        </a>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 mt-3">Nouvelle évaluation</h1>
        <p class="mt-1 text-sm text-slate-500">Réglez le cadre de l'épreuve, les questions viendront ensuite</p>
    </div>

    <form method="POST" action="{{ route('admin.quizzes.store') }}" class="space-y-6">
        @csrf

        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 space-y-5">
            <h2 class="text-base font-semibold text-slate-900">Présentation</h2>

            <div>
                <label for="title" class="block text-sm font-medium text-slate-700 mb-1.5">Titre <span class="text-red-500" aria-hidden="true">*</span></label>
                <input type="text" name="title" id="title" required value="{{ old('title') }}"
                       placeholder="Ex : Examen final - Algorithmique"
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                @error('title')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-slate-700 mb-1.5">Consignes</label>
                <textarea name="description" id="description" rows="3"
                          placeholder="Consignes affichées à l'étudiant avant de commencer…"
                          class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('description') }}</textarea>
                @error('description')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 space-y-5">
            <h2 class="text-base font-semibold text-slate-900">Déroulement</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="duration_minutes" class="block text-sm font-medium text-slate-700 mb-1.5">Durée (minutes) <span class="text-red-500" aria-hidden="true">*</span></label>
                    <input type="number" name="duration_minutes" id="duration_minutes" required min="1" max="600"
                           value="{{ old('duration_minutes', 30) }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    <p class="mt-1.5 text-xs text-slate-500">Le temps est décompté côté serveur : recharger la page ne le remet pas à zéro.</p>
                    @error('duration_minutes')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="max_submissions" class="block text-sm font-medium text-slate-700 mb-1.5">Quota de participants</label>
                    <input type="number" name="max_submissions" id="max_submissions" min="1" max="100000"
                           value="{{ old('max_submissions') }}" placeholder="Illimité"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    <p class="mt-1.5 text-xs text-slate-500">Laissez vide pour ne pas limiter le nombre de participants.</p>
                    @error('max_submissions')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="open_date" class="block text-sm font-medium text-slate-700 mb-1.5">Ouverture</label>
                    <input type="datetime-local" name="open_date" id="open_date" value="{{ old('open_date') }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    @error('open_date')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="close_date" class="block text-sm font-medium text-slate-700 mb-1.5">Fermeture</label>
                    <input type="datetime-local" name="close_date" id="close_date" value="{{ old('close_date') }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    @error('close_date')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Anonymat, note et surveillance</h2>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="is_anonymous" value="1" @checked(old('is_anonymous'))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40">
                <span>
                    <span class="block text-sm font-medium text-slate-700">Évaluation anonyme</span>
                    <span class="block text-xs text-slate-500">Aucun nom n'est enregistré. L'étudiant se connecte avec sa référence de 10 caractères, qui lui sert aussi de numéro d'anonymat.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="show_score" value="1" @checked(old('show_score', true))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40">
                <span>
                    <span class="block text-sm font-medium text-slate-700">Afficher la note à la fin</span>
                    <span class="block text-xs text-slate-500">L'étudiant voit sa note et le détail de la correction dès qu'il rend sa copie.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="proctoring" value="1" @checked(old('proctoring', true))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40">
                <span>
                    <span class="block text-sm font-medium text-slate-700">Surveillance de la fenêtre</span>
                    <span class="block text-xs text-slate-500">Bloque le copier-coller et le clic droit, passe en plein écran et journalise chaque sortie de la fenêtre. À savoir : un navigateur ne peut pas interdire techniquement les captures d'écran ni l'ouverture d'un autre onglet.</span>
                </span>
            </label>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center justify-center px-5 py-2.5 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 transition-all duration-150">
                Créer l'évaluation
            </button>
            <a href="{{ route('admin.quizzes.index') }}"
               class="inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                Annuler
            </a>
        </div>
    </form>
</div>
@endsection

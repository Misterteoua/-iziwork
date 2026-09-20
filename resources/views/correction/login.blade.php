@extends('layouts.app')

@section('title', 'Correction - ' . $grader->name)

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-0">
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        <p class="text-xs font-semibold text-brand-700 uppercase tracking-wider">Mission de correction</p>
        <h1 class="mt-1 text-xl font-bold tracking-tight text-slate-900">
            {{ $quizzes->isEmpty() ? 'Aucune évaluation affectée' : $quizzes->pluck('title')->join(' · ') }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Bonjour {{ $grader->name }}. Saisissez votre email et votre référence pour ouvrir vos copies.
        </p>

        @if($expired)
        {{-- Le lien fonctionne, l'accès est fermé : la différence compte pour
             celui qui est en train de corriger. --}}
        <div class="mt-5 rounded-xl bg-amber-50 border border-amber-200/70 px-4 py-3" role="alert">
            <p class="text-sm text-amber-800">
                <strong>Votre délai de correction est dépassé.</strong>
                Votre lien reste valable, mais l'accès est fermé : demandez la prolongation à l'organisation.
            </p>
        </div>
        @else
        <form method="POST" action="{{ route('correction.authenticate') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="code" value="{{ $code }}">

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Votre adresse email</label>
                <input id="email" name="email" type="email" required autocomplete="email" spellcheck="false"
                       value="{{ old('email') }}"
                       class="mt-1.5 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
            </div>

            <div>
                <label for="reference" class="block text-sm font-medium text-slate-700">Votre référence</label>
                <input id="reference" name="reference" type="text" required inputmode="latin" spellcheck="false"
                       autocomplete="off" placeholder="10 caractères"
                       value="{{ old('reference') }}"
                       class="mt-1.5 w-full px-4 py-2.5 border border-slate-300 rounded-xl font-mono uppercase tracking-widest text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                @error('reference')
                <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
                <p class="mt-1.5 text-xs text-slate-500">
                    Majuscules et espaces n'ont pas d'importance. Après cinq tentatives, attendez une minute.
                </p>
            </div>

            <button type="submit"
                    class="w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                Ouvrir mes copies
            </button>
        </form>
        @endif

        <p class="mt-5 text-xs text-slate-500">
            Le lien seul n'ouvre rien : sans votre référence, il ne donne accès à aucune copie. Ne le
            transmettez donc à personne, et demandez-en un nouveau s'il vous semble compromis.
        </p>
    </div>
</div>
@endsection

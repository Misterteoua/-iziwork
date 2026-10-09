@extends('layouts.app')

@section('title', 'Retrouver mon résultat - '.$quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        <a href="{{ route('quiz.start', $quiz->token) }}"
           class="text-sm font-semibold text-brand-700 hover:text-brand-800 underline underline-offset-4">
            ← Retour à l’évaluation
        </a>

        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Retrouver mon résultat</h1>
        <p class="mt-2 text-sm text-slate-600">
            Évaluation : <span class="font-semibold text-slate-800">{{ $quiz->title }}</span>
        </p>
        <p class="mt-3 text-sm text-slate-600">
            Saisissez la référence reçue, ou retrouvez une copie nominative avec le nom complet et
            l’adresse email enregistrés lors de l’évaluation.
        </p>

        @if(session('error'))
        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
            {{ session('error') }}
        </div>
        @endif

        <form method="POST" action="{{ route('quiz.result.recovery.submit', $quiz->token) }}"
              class="mt-6 rounded-xl border border-slate-200 p-4 sm:p-5 space-y-4">
            @csrf
            <input type="hidden" name="method" value="reference">
            <div>
                <label for="reference" class="block text-sm font-medium text-slate-700">Référence de l’évaluation</label>
                <input id="reference" name="reference" type="text" maxlength="32"
                       autocomplete="off" autocapitalize="characters" spellcheck="false"
                       class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 font-mono tracking-widest text-slate-900 focus-visible:border-brand-500 focus-visible:ring-2 focus-visible:ring-brand-500/40"
                       placeholder="Ex. ABCDEFGHJK">
                <p class="mt-1.5 text-xs text-slate-500">Les espaces et les minuscules sont acceptés.</p>
            </div>
            <button type="submit"
                    class="w-full inline-flex items-center justify-center rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Rechercher avec ma référence
            </button>
        </form>

        @if($canUseIdentity)
        <div class="my-6 flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-slate-400" aria-hidden="true">
            <span class="h-px flex-1 bg-slate-200"></span>
            ou
            <span class="h-px flex-1 bg-slate-200"></span>
        </div>

        <form method="POST" action="{{ route('quiz.result.recovery.submit', $quiz->token) }}"
              class="rounded-xl border border-slate-200 p-4 sm:p-5 space-y-4">
            @csrf
            <input type="hidden" name="method" value="identity">
            <div>
                <label for="student_name" class="block text-sm font-medium text-slate-700">Nom complet enregistré</label>
                <input id="student_name" name="student_name" type="text" maxlength="255" required autocomplete="name"
                       class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus-visible:border-brand-500 focus-visible:ring-2 focus-visible:ring-brand-500/40">
            </div>
            <div>
                <label for="student_email" class="block text-sm font-medium text-slate-700">Adresse email enregistrée</label>
                <input id="student_email" name="student_email" type="email" maxlength="255" required autocomplete="email"
                       class="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 focus-visible:border-brand-500 focus-visible:ring-2 focus-visible:ring-brand-500/40">
            </div>
            <button type="submit"
                    class="w-full inline-flex items-center justify-center rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40">
                Rechercher avec mon identité
            </button>
        </form>
        @else
        <p class="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Cette évaluation est anonyme : la référence est le seul moyen de retrouver votre copie.
        </p>
        @endif

        <p class="mt-5 text-xs leading-relaxed text-slate-500">
            Seule une copie rendue peut être retrouvée. Si vous ne disposez plus de votre référence
            ou si vos informations ne correspondent pas, contactez l’enseignant.
        </p>
    </div>
</div>
@endsection

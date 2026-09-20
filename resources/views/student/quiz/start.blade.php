@extends('layouts.app')

@section('title', $quiz->title)

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $quiz->title }}</h1>

        @if($quiz->description)
        <p class="mt-3 text-sm text-slate-600 whitespace-pre-line">{{ $quiz->description }}</p>
        @endif

        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-6">
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Durée</dt>
                <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $quiz->quizDurationMinutes() }} min</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Questions</dt>
                <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $questionsCount }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-4 py-3">
                <dt class="text-xs text-slate-500">Total</dt>
                <dd class="text-lg font-bold text-slate-900" style="font-variant-numeric: tabular-nums">{{ $maxScore }} pt</dd>
            </div>
        </dl>

        <ul class="mt-6 space-y-2 text-sm text-slate-600">
            <li class="flex items-start gap-2">
                <span class="text-brand-600" aria-hidden="true">•</span>
                Une seule question s'affiche à la fois. Une fois validée, elle n'est plus modifiable.
            </li>
            <li class="flex items-start gap-2">
                <span class="text-brand-600" aria-hidden="true">•</span>
                Le temps est décompté par le serveur dès que vous commencez : fermer la page ne l'arrête pas.
            </li>
            @if($quiz->quizUsesProctoring())
            <li class="flex items-start gap-2">
                <span class="text-amber-600" aria-hidden="true">•</span>
                La fenêtre est surveillée : le copier-coller est bloqué, et sont enregistrés le passage à un
                autre onglet et la sortie du plein écran. Valider une réponse n'est jamais compté.
            </li>
            @endif
            @if($quiz->quizShowsScore())
            <li class="flex items-start gap-2">
                <span class="text-brand-600" aria-hidden="true">•</span>
                Votre note et la correction s'affichent dès que vous rendez votre copie.
            </li>
            @endif
        </ul>

        @if($finishedAttempt ?? null)
        {{-- Salle informatique : la copie précédente ne doit pas confisquer le
             poste. Elle reste accessible par ce lien, et le formulaire
             ci-dessous est celui du candidat suivant. --}}
        <div class="mt-6 rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
            <p class="text-sm text-slate-700">
                Une copie a déjà été rendue sur cet appareil
                (référence <span class="font-mono font-semibold">{{ $finishedAttempt->reference }}</span>).
                <a href="{{ route('quiz.result', $quiz->token) }}" class="font-semibold text-brand-700 hover:text-brand-800">Voir ce résultat</a>
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Vous êtes l'étudiant suivant ? Remplissez le formulaire ci-dessous : vous obtiendrez
                votre propre copie, avec votre propre référence.
            </p>
        </div>
        @endif

        @if(! $quiz->quizIsOpen())
        <div class="mt-6 rounded-xl bg-slate-100 border border-slate-200 px-4 py-3 text-sm text-slate-700" role="alert">
            Cette évaluation n'est pas ouverte actuellement.
        </div>
        @else
        <form method="POST" action="{{ route('quiz.begin', $quiz->token) }}" class="mt-8 space-y-5">
            @csrf

            @if($usesReferences)
            <div>
                <label for="reference" class="block text-sm font-medium text-slate-700 mb-1.5">
                    Votre référence <span class="text-red-500" aria-hidden="true">*</span>
                </label>
                <input type="text" name="reference" id="reference" required autofocus
                       maxlength="10" autocapitalize="characters" autocomplete="off" spellcheck="false"
                       value="{{ old('reference') }}"
                       placeholder="10 caractères"
                       class="w-full px-4 py-3 border border-slate-300 rounded-xl text-lg font-mono tracking-widest text-slate-900 placeholder-slate-300 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                <p class="mt-1.5 text-xs text-slate-500">La référence qui vous a été remise. Elle tient lieu de numéro d'anonymat.</p>
                @error('reference')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>
            @endif

            @unless($quiz->is_anonymous)
            <div>
                <label for="student_name" class="block text-sm font-medium text-slate-700 mb-1.5">
                    Nom complet <span class="text-red-500" aria-hidden="true">*</span>
                </label>
                <input type="text" name="student_name" id="student_name" @if(!$usesReferences) required @endif
                       value="{{ old('student_name') }}"
                       class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                @error('student_name')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="student_email" class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                    <input type="email" name="student_email" id="student_email" value="{{ old('student_email') }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                    @error('student_email')<p class="mt-1.5 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="student_major" class="block text-sm font-medium text-slate-700 mb-1.5">Filière</label>
                    <input type="text" name="student_major" id="student_major" value="{{ old('student_major') }}"
                           class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                </div>
            </div>
            @endunless

            @if($quiz->quizUsesProctoring())
            {{-- Le plein écran exige un geste de l'utilisateur, et il ne survit pas
                 au chargement de la page suivante : le choix est mémorisé ici et
                 proposé par un bouton dédié sur l'épreuve, au lieu d'être
                 déclenché au hasard d'un clic. --}}
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <label for="fullscreen-choice" class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="fullscreen" id="fullscreen-choice" value="1" checked
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-500/40">
                    <span class="text-sm text-slate-700">
                        <span class="font-semibold">Passer l'épreuve en plein écran</span> (recommandé)
                        <span class="mt-0.5 block text-xs text-slate-500">
                            Limite les distractions. Vous pourrez en sortir à tout moment, et cela
                            n'empêche jamais de valider une réponse.
                        </span>
                    </span>
                </label>
            </div>
            @endif

            @if($questionsCount === 0)
            <div class="rounded-xl bg-amber-50 border border-amber-200/70 px-4 py-3 text-sm text-amber-800" role="alert">
                Cette évaluation ne contient encore aucune question.
            </div>
            @endif

            <button type="submit" @disabled($questionsCount === 0)
                    class="w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white gradient-bg hover:opacity-95 transition-all duration-150 disabled:opacity-50 disabled:cursor-not-allowed">
                Commencer l'évaluation
            </button>
            <p class="text-xs text-slate-500 text-center">Le chronomètre démarre dès que vous cliquez.</p>
        </form>
        @endif
    </div>
</div>
@endsection


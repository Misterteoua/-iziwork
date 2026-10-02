@extends('layouts.app')

@section('title', 'Aperçu de l\'import - ' . $quiz->title)

@section('content')
{{-- Aperçu de l'import : ce qui serait créé, montré avant que rien ne le soit.

     Tout est dans un seul formulaire : chaque bouton renvoie l'état complet des
     énoncés, donc une retouche n'est jamais perdue par une action voisine. Les
     boutons « Fusionner » et « Retirer » n'écrivent rien — ils font relire la
     page ; seul « Confirmer l'import » enregistre.

     Variables attendues : $quiz, $import (l'aperçu enregistré, qui porte le jeu
     de questions), $questions (jeu en cours de relecture), $outcome (compte
     rendu de l'analyse), $questionErrors (messages par question, après une
     retouche refusée). --}}
<div class="px-4 sm:px-0 max-w-4xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('admin.quizzes.show', $quiz) }}"
           class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-brand-600 transition-colors duration-150">
            <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Retour à l'évaluation
        </a>

        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Aperçu de l'import</h1>
        <p class="mt-1 text-sm text-slate-500">
            Rien n'est encore enregistré. Vérifiez les questions ci-dessous — vous pouvez en retirer,
            recoudre un énoncé découpé, scinder une question en deux, ou déplacer la limite entre deux
            questions — puis confirmez.
        </p>
        <p class="mt-2 text-xs text-slate-500">
            Cet aperçu est <strong>enregistré</strong> : vous pouvez fermer cette page, vous déconnecter,
            ou la rouvrir depuis un autre appareil — il vous attendra, sans retéléverser le fichier.
        </p>
    </div>

    {{-- Compte rendu de l'analyse --}}
    <div class="rounded-2xl border px-5 py-4 mb-6 {{ $outcome->hasErrors() ? 'bg-amber-50 border-amber-200/70 text-amber-900' : 'bg-emerald-50 border-emerald-200/70 text-emerald-800' }}"
         role="status">
        {{-- « détectée », et non « importée » : à cet instant rien n'est encore
             écrit. Le compte rendu final, lui, parlera de questions importées. --}}
        <p class="text-sm font-medium">{{ $outcome->summary('question détectée', 'questions détectées') }}</p>

        @if($outcome->splits !== [])
        <div class="mt-3">
            <p class="text-xs font-semibold uppercase tracking-wider">Énoncés découpés</p>
            <ul class="mt-1 space-y-1 text-xs">
                @foreach($outcome->splits as $split)
                <li>{{ $split }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if($outcome->errors !== [])
        <div class="mt-3">
            <p class="text-xs font-semibold uppercase tracking-wider">Lignes à corriger</p>
            <ul class="mt-1 space-y-1 text-xs">
                @foreach($outcome->shownErrors() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
            @if($outcome->hiddenErrorsCount() > 0)
            <p class="mt-1 text-xs">… et {{ $outcome->hiddenErrorsCount() }} autre(s) ligne(s) à corriger.</p>
            @endif
        </div>
        @endif
    </div>

    @if($questions === [])
    <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 text-center">
        <p class="text-sm text-slate-600">
            Aucune question ne reste à importer. Relancez l'import pour en ajouter.
        </p>
        <a href="{{ route('admin.quizzes.show', $quiz) }}"
           class="mt-4 inline-flex items-center justify-center px-4 py-2.5 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150">
            Retour à l'évaluation
        </a>
    </div>
    @else
    <form method="POST" action="{{ route('admin.quizzes.questions.import.apply', [$quiz, $import]) }}">
        @csrf

        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-slate-100">
                <h2 class="text-base font-semibold text-slate-900">
                    {{ count($questions) }} question(s) seront ajoutée(s)
                </h2>
                <p class="mt-1 text-xs text-slate-500">
                    Elles s'ajoutent à la suite de celles qui existent déjà.
                </p>
            </div>

            <ul class="divide-y divide-slate-100">
                @foreach($questions as $index => $question)
                @php($isOpen = $question['field_type'] === \App\Models\FormField::OPEN_TYPE)
                @php($previous = $questions[$index - 1] ?? null)
                @php($origin = $question['group'] ?? $question['split_line'] ?? null)
                @php($previousOrigin = $previous === null ? null : ($previous['group'] ?? $previous['split_line'] ?? null))
                @php($sameSplit = $origin !== null && $origin === $previousOrigin)
                <li class="p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            @include('partials.question-text', [
                                'text' => $question['field_label'],
                                'prefix' => ($index + 1).'.',
                                'class' => 'text-sm font-medium text-slate-900',
                            ])
                        </div>
                        <span class="shrink-0 text-xs text-slate-500">{{ $question['points'] }} point(s)</span>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                            {{ $isOpen ? 'Réponse rédigée' : ($question['field_type'] === 'checkbox' ? 'Choix multiples' : 'Choix unique') }}
                        </span>

                        @isset($question['split_total'])
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-brand-50 text-brand-800"
                              title="Cette question fait partie d'un énoncé découpé.">
                            @isset($question['split_line'])
                            Découpée de la ligne {{ $question['split_line'] }} —
                            @else
                            Scindée —
                            @endisset
                            {{ $question['split_index'] }}/{{ $question['split_total'] }}
                        </span>
                        @endisset
                    </div>

                    @isset($questionErrors[$index])
                    <p class="mt-2 text-sm text-red-600" role="alert">{{ $questionErrors[$index] }}</p>
                    @endisset

                    @unless($isOpen)
                    <ul class="mt-3 space-y-1">
                        @foreach($question['options'] as $position => $option)
                        @php($isCorrect = in_array($position, $question['correct_answer'], true))
                        <li class="text-xs flex items-start gap-2 {{ $isCorrect ? 'text-emerald-700 font-medium' : 'text-slate-600' }}">
                            <span aria-hidden="true">{{ $isCorrect ? '✓' : '·' }}</span>
                            <span>{{ chr(65 + $position) }}. {{ $option }}</span>
                        </li>
                        @endforeach
                    </ul>
                    @endunless

                    {{-- Déplacer la limite, scinder : c'est le texte qui décide où
                         passe la coupure. Le panneau est replié pour que cinquante
                         questions tiennent à l'écran, mais son contenu part toujours. --}}
                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs font-medium text-brand-700 hover:text-brand-800">
                            Modifier l'énoncé, déplacer la limite ou scinder
                        </summary>
                        <label for="label-{{ $index }}" class="mt-2 block text-xs text-slate-500">Énoncé</label>
                        <textarea name="questions[{{ $index }}][label]" id="label-{{ $index }}" rows="4"
                                  class="mt-1 w-full px-3 py-2 border border-slate-300 rounded-xl text-xs text-slate-900 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ $question['field_label'] }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">
                            Pour déplacer la limite, coupez la fin d'une question et collez-la au début de la suivante.
                            Pour <strong>scinder</strong> cette question en deux, insérez une ligne ne contenant que trois
                            tirets (<span class="font-mono">---</span>) à l'endroit de la coupure, puis cliquez « Scinder à la coupure ».
                            La mise en forme (paragraphes, listes) s'applique à l'affichage, pas au texte stocké.
                        </p>
                    </details>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if($sameSplit)
                        <button type="submit" name="action" value="merge:{{ $index }}"
                                class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-lg text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                            Fusionner avec la précédente
                        </button>
                        @endif

                        <button type="submit" name="action" value="split:{{ $index }}"
                                class="inline-flex items-center justify-center px-3.5 py-2 border border-slate-300 text-xs font-semibold rounded-lg text-slate-700 hover:bg-slate-50 transition-colors duration-150">
                            Scinder à la coupure
                        </button>

                        <button type="submit" name="action" value="remove:{{ $index }}"
                                class="inline-flex items-center justify-center px-3.5 py-2 border border-red-200 text-xs font-semibold rounded-lg text-red-700 hover:bg-red-50 transition-colors duration-150">
                            Retirer cette question
                        </button>
                    </div>
                </li>
                @endforeach
            </ul>
        </div>

        <div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <button type="submit" name="action" value="confirm"
                    class="inline-flex items-center justify-center px-5 py-3 border border-transparent text-sm font-semibold rounded-xl text-white bg-brand-600 hover:bg-brand-700 transition-colors duration-150">
                Confirmer l'import ({{ count($questions) }})
            </button>

            <a href="{{ route('admin.quizzes.show', $quiz) }}"
               class="inline-flex items-center justify-center px-5 py-3 border border-slate-300 text-sm font-semibold rounded-xl text-slate-700 hover:bg-slate-50 transition-colors duration-150 self-start">
                Retour à l'évaluation
            </a>
        </div>
    </form>

    {{-- Abandonner : l'aperçu enregistré disparaît, et rien n'est ajouté. Hors
         du formulaire principal, un formulaire ne pouvant pas en contenir un autre. --}}
    <form method="POST" action="{{ route('admin.quizzes.questions.import.discard', [$quiz, $import]) }}"
          class="mt-4 text-center"
          onsubmit="return confirm('Abandonner cet aperçu ? Aucune question ne sera ajoutée.');">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 transition-colors duration-150">
            Abandonner cet aperçu
        </button>
    </form>
    @endif
</div>
@endsection

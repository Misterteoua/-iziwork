{{-- Une question de la copie en cours de correction, avec sa note, son
     commentaire et sa relecture.

     Partagé par la correction de l'administrateur et celle d'un correcteur
     externe : les deux doivent voir exactement le même écran, sinon une note
     pourrait dépendre de qui a corrigé. Seule la route du formulaire, définie
     par la vue englobante, change — et le droit de reprise, porté par `$asAdmin`.

     Variables attendues : $attempt, $question, $index, $answers, $asAdmin. --}}
@php($answer = $answers[$question->id] ?? null)
@php($review = $answer?->latestReview())
@php($locked = $answer !== null && ! $asAdmin && $answer->reviewedByAdmin())

{{-- Une question est corrigée à la main si c'est une question rédigée, ou si
     l'évaluation a retiré l'auto-correction de ses QCM. C'est ce seul critère
     qui décide de l'écran : les deux cas montrent la même chose, parce qu'ils
     attendent exactement la même décision de l'enseignant. --}}
@php($manual = $question->isOpen() || ($question->isChoice() && $attempt->form->quizGradesChoiceManually()))
@php($chosen = $answer?->chosenIndexes() ?? [])
{{-- Les pièces jointes comptent comme réponse : un document seul doit rendre la
     question corrigeable, exactement comme un texte. Le calcul est fait ici, une
     fois pour toutes. --}}
@php($attachments = $attempt->attachmentsFor($question))
@php($responded = $answer !== null && ($question->isOpen()
    ? (trim((string) $answer->answer_text) !== '' || $attachments->isNotEmpty())
    : $chosen !== []))

@php($points = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ','))

<div class="bg-white rounded-2xl shadow-card border border-slate-200/70 p-6">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            @include('partials.question-text', [
                'text' => $question->field_label,
                'prefix' => ($index + 1).'.',
                'class' => 'text-sm font-medium text-slate-900',
            ])
        </div>
        <span class="shrink-0 text-xs text-slate-500">{{ $question->points }} point(s)</span>
    </div>

    @if($manual)
        @if($question->isOpen())
            @if($question->expected_answer)
            <div class="mt-4 rounded-xl bg-brand-50/60 border border-brand-100 px-4 py-3">
                <p class="text-xs font-semibold text-brand-800 uppercase tracking-wider">Réponse attendue (guide)</p>
                @include('partials.question-text', ['text' => $question->expected_answer, 'class' => 'mt-1 text-xs text-slate-700'])
            </div>
            @endif
        @else
            {{-- QCM corrigé à la main : l'enseignant doit voir la réponse
                 attendue à côté de celle du candidat, mais c'est lui qui décide
                 des points — aucune comparaison automatique n'est appliquée. --}}
            @php($correctLabels = [])
            @foreach($attempt->displayOptions($question) as $option)
                @if(in_array($option['original'], $question->correctIndexes(), true))
                    @php($correctLabels[] = $option['label'])
                @endif
            @endforeach
            <div class="mt-4 rounded-xl bg-brand-50/60 border border-brand-100 px-4 py-3">
                <p class="text-xs font-semibold text-brand-800 uppercase tracking-wider">Bonne réponse (indicative)</p>
                <p class="mt-1 text-xs text-slate-700">{{ $correctLabels === [] ? '—' : implode(' ; ', $correctLabels) }}</p>
            </div>
        @endif

        <div class="mt-4 rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Réponse de l'étudiant</p>
            @if(! $responded)
                <p class="mt-1 text-sm text-slate-500">Aucune réponse rendue : rien à corriger, la question vaut zéro.</p>
            @elseif($question->isOpen())
                @if(trim((string) $answer->answer_text) === '')
                <p class="mt-1 text-sm text-slate-800">Réponse rendue par un document joint — voir les pièces jointes ci-dessous.</p>
                @else
                @include('partials.question-text', ['text' => $answer->answer_text, 'class' => 'mt-1 text-sm text-slate-800'])
                @endif
            @else
                @php($chosenLabels = [])
                @foreach($attempt->displayOptions($question) as $option)
                    @if(in_array($option['original'], $chosen, true))
                        @php($chosenLabels[] = $option['label'])
                    @endif
                @endforeach
                <p class="mt-1 text-sm text-slate-800">{{ $chosenLabels === [] ? 'sans réponse' : implode(' ; ', $chosenLabels) }}</p>
            @endif
        </div>

        {{-- Pièces jointes déposées par l'étudiant : leur nom, leur taille et un
             lien. Le fichier n'est jamais embarqué dans la page, il se
             télécharge par une route qui revérifie l'accès. --}}
        @if($attachments->isNotEmpty())
        {{-- Route et paramètres selon le contexte (administration ou correcteur) :
             le téléchargement et l'aperçu passent par la même porte, qui revérifie
             l'accès à chaque appel. --}}
        @php($attachmentRoute = $asAdmin ? 'admin.quizzes.attempts.attachment' : 'correction.attachment')
        @php($attachmentBase = $asAdmin ? [$attempt->form_id, $attempt] : [$attempt])
        <div class="mt-3 rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Pièces jointes</p>
            <ul class="mt-2 space-y-3">
                @foreach($attachments as $attachment)
                <li class="text-sm">
                    @if($attachment->isImage())
                    {{-- Aperçu de l'image : la vignette ouvre la même image en
                         grand dans une visionneuse posée sur la page, sans
                         changer d'onglet ni quitter la correction. Seules les
                         images sont montrées ; un document reste un lien. --}}
                    @once
                    <style>
                        .izw-lightbox { display: none; }
                        .izw-lightbox:target { display: flex; position: fixed; inset: 0; z-index: 60; align-items: center; justify-content: center; padding: 1rem; }
                        .izw-lightbox-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.82); }
                        .izw-lightbox-body { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 0.6rem; margin: 0; max-width: min(92vw, 1100px); max-height: 92vh; }
                        .izw-lightbox-image { display: block; max-width: 92vw; max-height: 80vh; width: auto; height: auto; margin: 0 auto; border-radius: 0.6rem; background: #fff; }
                        .izw-lightbox-caption { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; color: #e2e8f0; font-size: 0.8rem; }
                        .izw-lightbox-caption a { color: #93c5fd; text-decoration: underline; text-underline-offset: 2px; }
                    </style>
                    @endonce
                    <a href="#apercu-{{ $attachment->id }}" id="apercu-lien-{{ $attachment->id }}"
                       class="inline-block group" title="Agrandir l'aperçu">
                        <img src="{{ route($attachmentRoute, [...$attachmentBase, $attachment, 'apercu' => 1]) }}"
                             alt="Aperçu de {{ $attachment->original_name }}" loading="lazy"
                             class="max-h-48 w-auto rounded-lg border border-slate-200 group-hover:border-brand-400 transition-colors duration-150">
                    </a>
                    {{-- Visionneuse : ouverte par la vignette, refermée par un clic
                         sur le fond ou sur « Fermer ». Elle n'existe qu'à l'état
                         ciblé (`:target`), donc aucun script n'est nécessaire. --}}
                    <div id="apercu-{{ $attachment->id }}" class="izw-lightbox"
                         role="dialog" aria-modal="true" aria-label="Aperçu de {{ $attachment->original_name }}">
                        <a href="#apercu-lien-{{ $attachment->id }}" class="izw-lightbox-backdrop" aria-label="Fermer l'aperçu"></a>
                        <figure class="izw-lightbox-body">
                            <img src="{{ route($attachmentRoute, [...$attachmentBase, $attachment, 'apercu' => 1]) }}"
                                 alt="Aperçu de {{ $attachment->original_name }}" class="izw-lightbox-image">
                            <figcaption class="izw-lightbox-caption">
                                <span>{{ $attachment->original_name }}</span>
                                <a href="{{ route($attachmentRoute, [...$attachmentBase, $attachment]) }}">Télécharger</a>
                                <a href="#apercu-lien-{{ $attachment->id }}">Fermer</a>
                            </figcaption>
                        </figure>
                    </div>
                    @endif
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <a href="{{ route($attachmentRoute, [...$attachmentBase, $attachment]) }}"
                           class="font-medium text-brand-700 hover:text-brand-900 underline underline-offset-2">
                            {{ $attachment->original_name }}
                        </a>
                        <span class="text-xs text-slate-500">{{ $attachment->formatted_size }}</span>
                    </div>
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        @if($responded)
            @if($locked)
            {{-- Le correcteur devant une note reprise : il voit la note retenue,
                 le motif, et comprend pourquoi il ne peut plus y toucher. --}}
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3">
                <p class="text-xs font-semibold text-amber-800 uppercase tracking-wider">Note reprise par l'administration</p>
                <p class="mt-1 text-sm text-slate-800">
                    Note retenue :
                    <span class="font-semibold">{{ $points($answer->points_awarded) }} / {{ $points($question->points) }}</span>
                </p>
                @if($review?->reviewed_by_admin_id !== null)
                <p class="mt-1 text-xs text-slate-700">
                    Décision du {{ $review->created_at->format('d/m/Y à H:i') }}
                    @if($review->reason) · motif : {{ $review->reason }} @endif
                </p>
                @endif
                @if($answer->hasComment())
                <p class="mt-2 text-xs text-slate-700">Commentaire retenu :</p>
                @include('partials.question-text', ['text' => $answer->grader_comment, 'class' => 'mt-0.5 text-xs text-slate-700'])
                @endif
                <p class="mt-2 text-xs text-amber-800">
                    Vous ne pouvez plus modifier cette réponse : la relecture de l'administration est définitive.
                </p>
            </div>
            @else
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <label for="points-{{ $answer->id }}" class="text-sm font-medium text-slate-700">
                    Points attribués <span class="text-slate-400">/ {{ $question->points }}</span>
                </label>
                <input type="number" name="points[{{ $answer->id }}]" id="points-{{ $answer->id }}"
                       step="0.5" min="0" max="{{ $question->points }}"
                       value="{{ old('points.'.$answer->id, $answer->points_awarded) }}"
                       placeholder="En attente"
                       class="w-32 px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">
                <span class="text-xs text-slate-500">
                    {{ $answer->isGraded() ? 'Note enregistrée' : 'Pas encore corrigée' }} · une note partielle est acceptée
                </span>
            </div>
            @error('points.'.$answer->id)
            <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
            @enderror

            {{-- Le commentaire part avec la note, et se voit dans le bulletin de
                 l'étudiant dès que la copie est entièrement corrigée. --}}
            <div class="mt-4">
                <label for="comment-{{ $answer->id }}" class="text-sm font-medium text-slate-700">
                    Commentaire pour l'étudiant <span class="text-slate-400">(facultatif)</span>
                </label>
                <textarea name="comments[{{ $answer->id }}]" id="comment-{{ $answer->id }}" rows="2" maxlength="2000"
                          placeholder="Ex. : les deux étapes sont justes, le calcul final est faux."
                          class="mt-2 w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:border-brand-500 transition-colors duration-150">{{ old('comments.'.$answer->id, $answer->grader_comment) }}</textarea>
                <p class="mt-1 text-xs text-slate-500">
                    L'étudiant le lit une fois la copie entièrement corrigée, jamais sur une note provisoire.
                </p>
                @error('comments.'.$answer->id)
                <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            {{-- Reprendre la note d'un correcteur : l'administration doit dire
                 pourquoi, sinon la trace ne dirait rien à celui qui la lirait. --}}
            @if($asAdmin && $answer->heldByGrader() && $answer->isGraded())
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3">
                <label for="reason-{{ $answer->id }}" class="text-sm font-medium text-amber-900">
                    Motif de la reprise <span class="text-amber-700">(obligatoire pour modifier cette note)</span>
                </label>
                <p class="mt-1 text-xs text-amber-800">
                    Cette note a été posée par {{ $answer->gradedByLabel() }}. La modifier vous l'attribue,
                    et son auteur d'origine est conservé au journal de la copie.
                </p>
                <input type="text" name="review_reason[{{ $answer->id }}]" id="reason-{{ $answer->id }}" maxlength="500"
                       value="{{ old('review_reason.'.$answer->id) }}"
                       placeholder="Ex. : barème mal appliqué, réponse complète non comptée."
                       class="mt-2 w-full px-4 py-2.5 border border-amber-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus-visible:ring-2 focus-visible:ring-amber-500/40 focus-visible:border-amber-500 transition-colors duration-150">
                @error('review_reason.'.$answer->id)
                <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>
            @endif

            {{-- Ce que la note est devenue : la relecture est lisible, pas
                 seulement stockée. --}}
            @if($review !== null)
            <div class="mt-3 rounded-xl bg-slate-50 border border-slate-200/70 px-4 py-3">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Journal de la note</p>
                <p class="mt-1 text-xs text-slate-700">
                    @if($review->reviewed_by_admin_id !== null)
                        Relecture du {{ $review->created_at->format('d/m/Y à H:i') }} par l'administration
                        @if($review->reason) — motif : {{ $review->reason }} @endif
                    @else
                        Nouvelle décision de {{ $review->previousAuthorLabel() }}
                        le {{ $review->created_at->format('d/m/Y à H:i') }}
                    @endif
                </p>
                @if($review->previous_points !== null)
                <p class="mt-1 text-xs text-slate-500">
                    Note précédente : {{ $points($review->previous_points) }} / {{ $points($question->points) }}
                    @if($review->previousAuthorLabel()) ({{ $review->previousAuthorLabel() }}) @endif
                </p>
                @endif
                @if(trim((string) $review->previous_comment) !== '')
                <p class="mt-1 text-xs text-slate-500">Commentaire précédent :</p>
                @include('partials.question-text', ['text' => $review->previous_comment, 'class' => 'mt-0.5 text-xs text-slate-500'])
                @endif
            </div>
            @endif

            {{-- Qui a posé la note : sans cela, une note contestée n'a pas de
                 réponse, et deux correcteurs ne peuvent pas se relayer sans
                 savoir où en est la copie. --}}
            @if($answer->isGraded() && $answer->gradedByLabel())
            <p class="mt-2 text-xs text-slate-500">Note posée par {{ $answer->gradedByLabel() }}.</p>
            @endif
            @endif
        @endif
    @else
        @php($chosenLabels = [])
        @php($correctLabels = [])

        @foreach($attempt->displayOptions($question) as $optionPosition => $option)
            @if(in_array($option['original'], $chosen, true))
                @php($chosenLabels[] = $option['label'])
            @endif
            @if(in_array($option['original'], $question->correctIndexes(), true))
                @php($correctLabels[] = $option['label'])
            @endif
        @endforeach

        <p class="mt-3 text-xs text-slate-600">
            <span class="font-semibold text-slate-500 uppercase tracking-wider">Réponse de l'étudiant :</span>
            {{ $chosenLabels === [] ? 'sans réponse' : implode(' ; ', $chosenLabels) }}
        </p>
        <p class="mt-1 text-xs text-slate-600">
            <span class="font-semibold text-slate-500 uppercase tracking-wider">Bonne réponse :</span>
            {{ implode(' ; ', $correctLabels) }}
        </p>
        <p class="mt-2 text-xs font-medium {{ $answer?->is_correct ? 'text-emerald-700' : 'text-slate-500' }}">
            {{ $answer?->is_correct ? 'Juste — '.$answer->points_awarded.' point(s)' : 'Faux — 0 point' }}
            <span class="text-slate-400">· corrigée automatiquement</span>
        </p>
    @endif
</div>

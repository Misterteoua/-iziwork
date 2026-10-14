{{--
    Ce qu'enregistrer cette question ferait aux copies déjà rendues.

    Variables attendues :
      $quiz       l'évaluation
      $question   la question en cours de modification
      $preview    le résultat de QuizGrader::previewQuestionRegrade()

    Le contenu est rendu par le serveur, et non composé dans le navigateur : le
    calcul de la renotation vit là-bas, et une seconde mise en forme finirait par
    raconter autre chose que ce que l'enregistrement ferait.

    Le détail complet des copies tient sur la page d'aperçu du recalcul ; ici la
    place est comptée — la question est dans une liste, sous une phrase qui
    annonce déjà le compte —, et huit lignes suffisent à savoir ce qu'on
    s'apprête à faire.

    Rien de ce qui est écrit ici n'est une décision : le panneau montre, et le
    bouton d'enregistrement du formulaire qui l'entoure reste le seul à écrire.
--}}
@php($note = static fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ','))
@php($shown = array_slice($preview['rows'], 0, 8))

@if($preview['copies'] > 0)
<p>
    <span class="font-semibold">
        {{ $preview['copies'] }} copie(s) sur {{ $preview['finished'] }} verraient leur note ou leur barème changer.
    </span>
    Rien n'est encore écrit : ces copies sont notées comme aujourd'hui.
</p>

<ul class="mt-2 space-y-1">
    @foreach($shown as $row)
    @php($attempt = $row['attempt'])
    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <a href="{{ route('admin.quizzes.attempts.grade', [$quiz, $attempt]) }}"
           class="font-mono text-xs font-semibold underline decoration-amber-400 hover:decoration-amber-700">{{ $attempt->reference }}</a>

        @unless($quiz->is_anonymous)
        <span class="text-xs">{{ $attempt->student_name ?? '—' }}</span>
        @endunless

        <span class="text-xs" style="font-variant-numeric: tabular-nums">
            @if($row['from'] !== null && $row['to'] !== $row['from'])
                note {{ $note($row['from']) }} → <span class="font-semibold">{{ $note($row['to']) }}</span>
            @else
                note inchangée ({{ $note($row['to']) }})
            @endif
            / {{ $note($row['max_to']) }}
            @if($row['max_from'] !== null && $row['max_to'] !== $row['max_from'])
                <span class="whitespace-nowrap">· barème {{ $note($row['max_from']) }} → {{ $note($row['max_to']) }}</span>
            @endif
            @if($row['answers'] > 0)
                <span class="whitespace-nowrap">· {{ $row['answers'] }} réponse(s) renotée(s)</span>
            @endif
        </span>
    </li>
    @endforeach
</ul>

@if($preview['copies'] > count($shown))
<p class="mt-2 text-xs">
    … et {{ $preview['copies'] - count($shown) }} autre(s) copie(s), toutes annoncées sur la page de résultats.
</p>
@endif

@if($preview['quiet'] > 0)
{{-- Des renotations que le tableau ne peut pas montrer : deux réponses d'une
     même copie se compensent, le total ne bouge pas, mais le verdict de chaque
     question change sur la copie de l'étudiant. --}}
<p class="mt-2 text-xs">
    {{ $preview['quiet'] }} autre(s) réponse(s) seraient renotées sans déplacer le total de leur copie :
    le verdict change sur la copie de l'étudiant, pas la note.
</p>
@endif

@if($preview['released'] > 0)
<p class="mt-2 text-xs">
    {{ $preview['released'] }} copie(s) repasseraient en correction : la machine ne tranche plus cette
    question, leur note redevient provisoire.
</p>
@endif

@if($preview['reclaimed'] > 0)
<p class="mt-2 text-xs">
    {{ $preview['reclaimed'] }} copie(s) n'attendraient plus aucune correction une fois la question enregistrée.
</p>
@endif
@elseif($preview['answers'] > 0)
{{-- Aucun total ne bouge, mais l'enregistrement écrirait quand même : deux
     réponses d'une même copie se compensent, et le verdict se lit sur la copie
     de l'étudiant, total inchangé ou non. --}}
<p>
    <span class="font-semibold">Aucune note ne bouge.</span>
    {{ $preview['answers'] }} réponse(s) seraient renotées sans déplacer le total de leur copie.
</p>
@else
<p>
    <span class="font-semibold">Aucun changement.</span>
    Les {{ $preview['finished'] }} copie(s) déjà rendues sont notées d'après cette définition :
    l'enregistrer ne toucherait ni leur note ni leur barème.
</p>
@endif

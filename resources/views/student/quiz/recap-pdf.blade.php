<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Récapitulatif - {{ $quiz->title }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #333; }
        .header { background: #033299; color: white; padding: 30px; text-align: center; border-radius: 8px; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 22px; }
        .header p { margin: 10px 0 0; opacity: 0.85; }
        .header .logo { background: #ffffff; display: inline-block; padding: 8px 14px; border-radius: 8px; }
        .header .logo img { display: block; height: 30px; width: 116px; }
        .score { text-align: center; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .score .value { font-size: 34px; font-weight: bold; color: #033299; }
        .score .label { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .section { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .section h2 { font-size: 15px; color: #374151; margin: 0 0 15px; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td:first-child { font-weight: bold; color: #6b7280; width: 38%; }
        .question { margin-bottom: 14px; }
        .question .prompt { font-weight: bold; color: #374151; }
        .question .meta { font-size: 11px; color: #6b7280; }
        .good { color: #047857; }
        .bad { color: #b91c1c; }
        .footer { text-align: center; color: #9ca3af; font-size: 10px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .reference { font-family: "Courier New", monospace; font-size: 16px; letter-spacing: 2px; color: #033299; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/iziwork-logo.png'))) }}" alt="Iziwork">
        </div>
        <p>Récapitulatif d'évaluation</p>
    </div>

    @if($showScore && $attempt->score !== null)
    <div class="score">
        <div class="label">Note obtenue</div>
        <div class="value">{{ rtrim(rtrim(number_format((float) $attempt->score, 2, ',', ' '), '0'), ',') }} / {{ rtrim(rtrim(number_format((float) $attempt->max_score, 2, ',', ' '), '0'), ',') }}</div>
    </div>
    @endif

    <div class="section">
        <h2>Épreuve</h2>
        <table>
            <tr><td>Titre</td><td>{{ $quiz->title }}</td></tr>
            <tr><td>Durée impartie</td><td>{{ $quiz->quizDurationMinutes() }} minutes</td></tr>
            <tr><td>Référence du candidat</td><td class="reference">{{ $attempt->reference }}</td></tr>
            @unless($quiz->is_anonymous)
            <tr><td>Candidat</td><td>{{ $attempt->student_name ?? '—' }}</td></tr>
            @if($attempt->student_major)<tr><td>Filière</td><td>{{ $attempt->student_major }}</td></tr>@endif
            @endunless
            <tr><td>Temps utilisé</td><td>{{ gmdate('i:s', $attempt->elapsedSeconds()) }}</td></tr>
            <tr><td>Début</td><td>{{ $attempt->started_at?->format('d/m/Y à H:i') ?? '—' }}</td></tr>
            <tr>
                <td>Fin</td>
                <td>
                    {{ $attempt->submitted_at?->format('d/m/Y à H:i') ?? '—' }}
                    @if($attempt->status === \App\Models\QuizAttempt::STATUS_EXPIRED)
                        <span class="bad">(temps écoulé)</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if($showScore)
    <div class="section">
        <h2>Correction détaillée</h2>

        @foreach($attempt->questions() as $index => $question)
        @php($answer = $attempt->answers->firstWhere('form_field_id', $question->id))
        @php($chosen = $answer?->chosenIndexes() ?? [])
        <div class="question">
            <div class="prompt">{{ $index + 1 }}. {{ $question->field_label }}</div>
            <div class="meta">
                @if($answer === null)
                    <span class="bad">Sans réponse</span>
                @elseif($answer->is_correct)
                    <span class="good">Juste — {{ $question->points }} point(s)</span>
                @else
                    <span class="bad">Faux — 0 point</span>
                @endif
            </div>
            {{-- Les réponses sont désignées par leur intitulé et non par une
                 lettre : avec un mélange des propositions, la lettre « B »
                 n'est pas la même d'un candidat à l'autre. --}}
            @php($chosenLabels = [])
            @php($correctLabels = [])
            @foreach($attempt->displayOptions($question) as $option)
                @if(in_array($option['original'], $chosen, true))
                    @php($chosenLabels[] = $option['label'])
                @endif
                @if(in_array($option['original'], $question->correctIndexes(), true))
                    @php($correctLabels[] = $option['label'])
                @endif
            @endforeach
            <div class="meta">
                Votre réponse : {{ $chosenLabels === [] ? '—' : implode(' ; ', $chosenLabels) }}
                · Bonne réponse : {{ implode(' ; ', $correctLabels) }}
            </div>
        </div>
        @endforeach
    </div>
    @endif

    <div class="footer">
        <p>Ce document a été généré automatiquement par Iziwork</p>
        <p>{{ now()->format('d/m/Y à H:i') }}</p>
    </div>
</body>
</html>

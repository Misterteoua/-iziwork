{{--
    Récapitulatif PDF d'une évaluation.

    Document imprimé : la mise en page est écrite pour dompdf, qui ne connaît ni
    `grid` ni `flex`. Tout repose donc sur des tables et des bordures — c'est
    volontaire, et c'est ce qui garantit le même rendu sur toutes les
    installations.

    Deux logos encadrent le document :
      • en haut, le logo de l'établissement (`efsc-recap-pdf.png`) ;
      • en bas de page, le logo Iziwork, dans un pied de page répété sur chaque
        page.

    Les deux sont lus en base64 et de façon tolérante (voir App\Support\PdfAssets) :
    une image absente n'empêche jamais le document de se générer.

    Le contenu, lui, ne change pas d'un caractère : les règles de publication de
    la correction et de la note provisoire sont celles de la page de résultat.
--}}
@php
    $schoolLogo = \App\Support\PdfAssets::dataUri('images/efsc-recap-pdf.png');
    $brandLogo = \App\Support\PdfAssets::dataUri('images/iziwork-logo.png');
    $points = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',');
    // Numéro de document affiché dans le pied de page. La variable est fournie
    // par le contrôleur ; le `?? null` évite une erreur si la vue est rendue
    // seule (tests, aperçus).
    $documentId = $documentId ?? null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Récapitulatif - {{ $quiz->title }}</title>
    <style>
        /* ------------------------------------------------------------- Base */
        @page { margin: 110px 34px 90px 34px; }

        /* Police « cœur » de dompdf (Helvetica) : rien à embarquer, donc un PDF
           de quelques dizaines de kilo-octets au lieu d'un méga-octet. Les
           accents français et les tirets longs sont dans son encodage. */
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.55;
            color: #334155;
            margin: 0;
        }

        h1, h2, h3 { margin: 0; font-weight: bold; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }

        /* --------------------------------------------- En-tête (logo école) */
        .masthead { text-align: center; page-break-after: avoid; }

        .masthead .rule {
            height: 5px;
            background: #033299;
            border-radius: 3px;
            margin-bottom: 18px;
        }

        .masthead .school-logo { display: inline-block; }
        .masthead .school-logo img { height: 74px; }

        .masthead .eyebrow {
            margin-top: 14px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #64748b;
        }

        .masthead h1 {
            margin-top: 4px;
            font-size: 19px;
            color: #0f172a;
        }

        /* --------------------------------------------- Bandeau de la note */
        .hero {
            margin-top: 20px;
            background: #033299;
            border-radius: 10px;
            padding: 20px 22px;
            color: #ffffff;
            page-break-inside: avoid;
        }

        .hero table { width: 100%; }

        .hero .score {
            font-size: 40px;
            font-weight: bold;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            color: #ffffff;
        }

        .hero .score small {
            font-size: 18px;
            font-weight: normal;
            color: #b9cdf7;
        }

        .hero .score-label {
            margin-top: 6px;
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #cddcfb;
        }

        .hero .status {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .hero .status-ok { background: #10b981; color: #ffffff; }
        .hero .status-pending { background: #f59e0b; color: #3b2606; }

        /* Trois repères alignés à droite du bandeau. */
        .hero .facts { width: 100%; }
        .hero .facts td {
            border: 0;
            padding: 3px 0;
            text-align: right;
            font-size: 10px;
            color: #dbe6fd;
            vertical-align: top;
        }
        .hero .facts .fact-label {
            color: #9db8ef;
            padding-right: 10px;
            white-space: nowrap;
        }
        .hero .facts .fact-value {
            color: #ffffff;
            font-weight: bold;
            font-variant-numeric: tabular-nums;
        }
        .hero .facts .fact-value.mono {
            font-family: "Courier New", Courier, monospace;
            letter-spacing: 1.5px;
        }

        /* ------------------------------------------------ Encadré d'alerte */
        .notice {
            margin-top: 14px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 10px;
            color: #92400e;
            page-break-inside: avoid;
        }

        .notice strong { color: #78350f; }

        /* ---------------------------------------------------- Sections */
        .section {
            margin-top: 18px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 18px;
        }

        .section-title {
            font-size: 12px;
            color: #0f172a;
            padding-bottom: 9px;
            border-bottom: 2px solid #eef2f7;
            margin-bottom: 12px;
            /* Le titre ne doit jamais rester seul en bas d'une page : il part
               avec son contenu sur la suivante. */
            page-break-after: avoid;
        }

        .section-title .accent {
            display: inline-block;
            width: 4px;
            height: 12px;
            background: #0367f9;
            border-radius: 2px;
            margin-right: 7px;
            vertical-align: -1px;
        }

        /* Tableau clé/valeur : libellé discret à gauche, valeur lisible. */
        .kv td {
            padding: 7px 10px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        .kv tr:last-child td { border-bottom: 0; }
        .kv tr:nth-child(even) td { background: #f8fafc; }

        .kv .key {
            width: 36%;
            font-weight: bold;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .kv .value { color: #1e293b; }

        .reference {
            font-family: "Courier New", Courier, monospace;
            font-size: 14px;
            letter-spacing: 2px;
            color: #033299;
            font-weight: bold;
        }

        /* ------------------------------------------- Questions corrigées */
        .question {
            border: 1px solid #e2e8f0;
            border-left: 4px solid #94a3b8;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 12px;
            page-break-inside: avoid;
            background: #ffffff;
        }

        .question.is-good { border-left-color: #10b981; }
        .question.is-bad { border-left-color: #ef4444; }
        .question.is-pending { border-left-color: #f59e0b; }
        .question.is-skipped { border-left-color: #cbd5e1; }

        .question .prompt {
            font-weight: bold;
            color: #0f172a;
            font-size: 11px;
        }

        .question .verdict {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 9px;
            border-radius: 9px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .verdict.good { background: #ecfdf5; color: #047857; }
        .verdict.bad { background: #fef2f2; color: #b91c1c; }
        .verdict.pending { background: #fffbeb; color: #b45309; }
        .verdict.skipped { background: #f1f5f9; color: #64748b; }

        .question .answer {
            margin-top: 9px;
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-radius: 6px;
            padding: 8px 11px;
            color: #334155;
        }

        .question .answer .answer-label {
            display: block;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #94a3b8;
            margin-bottom: 3px;
        }

        .question .appreciation {
            margin-top: 9px;
            background: #eef2ff;
            border-left: 3px solid #033299;
            border-radius: 0 6px 6px 0;
            padding: 8px 11px;
            color: #1e293b;
        }

        .question .appreciation .answer-label {
            display: block;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #4f63b8;
            margin-bottom: 3px;
        }

        .question .attachment {
            margin-top: 6px;
            font-size: 10px;
            color: #475569;
        }

        .question .attachment a {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* Énoncé mis en forme (App\Support\QuestionText) : paragraphes et listes.
           dompdf ne connaît pas Tailwind, ces règles sont donc écrites ici. */
        .qt-p { margin: 0 0 6px; }
        .qt-list { margin: 0 0 6px; padding-left: 18px; }
        .qt-bullets { list-style-type: disc; }
        .qt-numbered { list-style-type: decimal; }
        .qt-alpha { list-style-type: lower-alpha; }

        .good { color: #047857; }
        .bad { color: #b91c1c; }
        .pending { color: #b45309; font-weight: bold; }

        /* ------------------------------------------- Suivre votre résultat */
        .follow { margin-top: 18px; }

        .follow .qr-cell { width: 150px; padding: 0 14px 0 0; vertical-align: top; }
        .follow .qr-cell img {
            width: 130px;
            height: 130px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px;
        }

        .follow .follow-text { vertical-align: top; color: #475569; font-size: 10px; }
        .follow .follow-link {
            display: block;
            margin-top: 8px;
            padding: 7px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-family: "Courier New", Courier, monospace;
            font-size: 9px;
            color: #033299;
            word-break: break-all;
        }

        /* ------------------------------------------------- Pied de page */
        /* Fixé : il se répète sur chaque page, et le corps du document garde une
           marge basse (voir @page) pour ne jamais le recouvrir. */
        .page-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -60px;
            height: 52px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            color: #94a3b8;
            font-size: 8.5px;
        }

        .page-footer .brand { display: inline-block; margin-bottom: 3px; }
        .page-footer .brand img { height: 20px; }
        .page-footer .brand-name {
            font-weight: bold;
            color: #64748b;
            letter-spacing: 0.4px;
        }

        /* Numéro de document : en monospace, pour qu'il se recopie sans erreur. */
        .page-footer .doc-id {
            font-family: "Courier New", Courier, monospace;
            color: #64748b;
            letter-spacing: 0.6px;
        }
    </style>
</head>
<body>
    {{-- Pied de page fixe : présent sur toutes les pages, logo Iziwork compris. --}}
    <div class="page-footer">
        @if($brandLogo)
        <div class="brand">
            <img src="{{ $brandLogo }}" alt="Iziwork">
        </div>
        @else
        <div class="brand-name">Iziwork</div>
        @endif
        <div>Document généré automatiquement par Iziwork — {{ now()->format('d/m/Y à H:i') }}</div>
        @if($documentId)
        <div>Numéro de document : <span class="doc-id">{{ $documentId }}</span></div>
        @endif
    </div>

    {{-- --------------------------------------------------- En-tête (école) --}}
    <div class="masthead">
        <div class="rule"></div>

        @if($schoolLogo)
        <div class="school-logo">
            <img src="{{ $schoolLogo }}" alt="EFSC">
        </div>
        @endif

        <div class="eyebrow">Récapitulatif d'évaluation</div>
        <h1>{{ $quiz->title }}</h1>
    </div>

    {{-- ------------------------------------------------------- La note --}}
    @if($showScore && $attempt->score !== null)
    <div class="hero">
        <table>
            <tr>
                <td style="vertical-align: top;">
                    <div class="score">
                        {{ $points($attempt->score) }} <small>/ {{ $points($attempt->max_score) }}</small>
                    </div>
                    <div class="score-label">
                        {{ $pending > 0 ? 'Note provisoire' : 'Note obtenue' }}
                    </div>
                    <div>
                        <span class="status {{ $pending > 0 ? 'status-pending' : 'status-ok' }}">
                            {{ $pending > 0 ? 'En attente de correction' : 'Correction définitive' }}
                        </span>
                    </div>
                </td>
                <td style="vertical-align: top; width: 46%;">
                    <table class="facts">
                        <tr>
                            <td class="fact-label">Référence</td>
                            <td class="fact-value mono">{{ $attempt->reference }}</td>
                        </tr>
                        <tr>
                            <td class="fact-label">Temps utilisé</td>
                            <td class="fact-value">{{ gmdate('i:s', $attempt->elapsedSeconds()) }}</td>
                        </tr>
                        <tr>
                            <td class="fact-label">Remise</td>
                            <td class="fact-value">{{ ($attempt->submitted_at ?? now())->format('d/m/Y à H:i') }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- Une réponse rédigée se corrige à la main : tant qu'il en reste une en
         attente, le document doit le dire, sinon il attesterait d'une note qui
         n'est pas encore arrêtée. --}}
    @if($pending > 0)
    <div class="notice">
        <strong>Note provisoire.</strong>
        {{ $pending }} réponse(s) rédigée(s) restent à corriger. Cette note deviendra
        définitive après la correction par l'enseignant.
    </div>
    @endif
    @endif

    {{-- --------------------------------------------------------- L'épreuve --}}
    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Épreuve</h2>
        <table class="kv">
            <tr><td class="key">Titre</td><td class="value">{{ $quiz->title }}</td></tr>
            <tr><td class="key">Durée impartie</td><td class="value">{{ $quiz->quizDurationMinutes() }} minutes</td></tr>
            <tr><td class="key">Référence du candidat</td><td class="value"><span class="reference">{{ $attempt->reference }}</span></td></tr>
            @unless($quiz->is_anonymous)
            <tr><td class="key">Candidat</td><td class="value">{{ $attempt->student_name ?? '—' }}</td></tr>
            @if($attempt->student_major)<tr><td class="key">Filière</td><td class="value">{{ $attempt->student_major }}</td></tr>@endif
            @endunless
            <tr><td class="key">Temps utilisé</td><td class="value">{{ gmdate('i:s', $attempt->elapsedSeconds()) }}</td></tr>
            <tr><td class="key">Début</td><td class="value">{{ $attempt->started_at?->format('d/m/Y à H:i') ?? '—' }}</td></tr>
            <tr>
                <td class="key">Fin</td>
                <td class="value">
                    {{ $attempt->submitted_at?->format('d/m/Y à H:i') ?? '—' }}
                    @if($attempt->status === \App\Models\QuizAttempt::STATUS_EXPIRED)
                        <span class="bad">(temps écoulé)</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- ------------------------------------------------ Correction détaillée --}}
    @if($showScore && $revealsCorrection)
    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Correction détaillée</h2>

        @foreach($attempt->questions() as $index => $question)
        @php($answer = $attempt->answers->firstWhere('form_field_id', $question->id))
        @php($chosen = $answer?->chosenIndexes() ?? [])
        {{-- Question notée à la main : réponse rédigée, ou QCM d'une évaluation
             sans auto-correction. Le document doit refléter la même règle que
             la page de résultat. --}}
        @php($manual = $question->isOpen() || ($question->isChoice() && $quiz->quizGradesChoiceManually()))
        @php($verdict = $answer === null
            ? 'is-skipped'
            : ($manual
                ? ($answer->isGraded() ? ($answer->points_awarded > 0 ? 'is-good' : 'is-bad') : 'is-pending')
                : ($answer->is_correct ? 'is-good' : 'is-bad')))
        <div class="question {{ $verdict }}">
            <div class="prompt">
                @include('partials.question-text', ['text' => $question->field_label, 'prefix' => ($index + 1).'.'])
            </div>

            <div>
                @if($answer === null)
                    <span class="verdict skipped">Sans réponse — 0 point</span>
                @elseif($manual)
                    {{-- Pas de verdict automatique : soit la note est attribuée,
                         soit la question attend encore une correction. --}}
                    @if($answer->isGraded())
                        <span class="verdict {{ $answer->points_awarded > 0 ? 'good' : 'bad' }}">
                            {{ $points($answer->points_awarded) }} / {{ $points($question->points) }} point(s)
                        </span>
                    @else
                        <span class="verdict pending">En attente de correction</span>
                    @endif
                @elseif($answer->is_correct)
                    <span class="verdict good">Juste — {{ $points($question->points) }} point(s)</span>
                @else
                    <span class="verdict bad">Faux — 0 point</span>
                @endif
            </div>

            @if($manual && $question->isOpen())
            <div class="answer">
                <span class="answer-label">Votre réponse</span>
                @php($attachments = $attempt->attachmentsFor($question))
                @if(trim((string) $answer?->answer_text) === '')
                    @if($attachments->isNotEmpty())
                    <span>Réponse rendue par un document joint.</span>
                    @else
                    <span class="pending">Aucune réponse rendue.</span>
                    @endif
                @else
                    @include('partials.question-text', ['text' => $answer->answer_text])
                @endif
            </div>

            {{-- Pièces jointes déposées par l'étudiant : leur nom, pour qu'il
                 retrouve dans le document ce qu'il a rendu. --}}
            @if($attachments->isNotEmpty())
            <div class="attachment">
                <span class="answer-label">Pièces jointes</span>
                @foreach($attachments as $attachment)
                    {{-- Un lien, pas seulement un nom : consulter le PDF en ligne
                         doit permettre d'ouvrir le document d'un clic. L'accès
                         repose sur la référence de la copie, la même clé que le
                         récapitulatif. --}}
                    <a href="{{ route('quiz.recap.attachment', [$quiz->token, $attempt->reference, $attachment]) }}">{{ $attachment->original_name }}</a> ({{ $attachment->formatted_size }})@if(! $loop->last) · @endif
                @endforeach
            </div>
            @endif

            {{-- L'appréciation du correcteur figure dans le document que
                 l'étudiant garde, mais seulement quand la copie est
                 entièrement corrigée : sur une note provisoire, elle serait
                 lue comme définitive. --}}
            @if($pending === 0 && $answer?->hasComment())
            <div class="appreciation">
                <span class="answer-label">Appréciation :</span>
                @include('partials.question-text', ['text' => $answer->grader_comment])
            </div>
            @endif
            @elseif($manual)
            {{-- QCM noté à la main : la réponse du candidat est rappelée, mais
                 aucune bonne réponse automatique n'est affichée — c'est
                 l'enseignant qui a jugé. --}}
            @php($chosenLabels = [])
            @foreach($attempt->displayOptions($question) as $option)
                @if(in_array($option['original'], $chosen, true))
                    @php($chosenLabels[] = $option['label'])
                @endif
            @endforeach
            <div class="answer">
                <span class="answer-label">Votre réponse</span>
                {{ $chosenLabels === [] ? '—' : implode(' ; ', $chosenLabels) }}
            </div>
            @else
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
            <div class="answer">
                <span class="answer-label">Votre réponse · Bonne réponse</span>
                {{ $chosenLabels === [] ? '—' : implode(' ; ', $chosenLabels) }}
                · <span class="good">{{ implode(' ; ', $correctLabels) }}</span>
            </div>
            @endif
        </div>
        @endforeach
    </div>
    @elseif($showScore)
    {{-- Le PDF se retélécharge à volonté avec la seule référence : c'est la
         porte de derrière du détail de la correction. La même règle que la
         page de résultat s'y applique donc, sans exception. --}}
    <div class="notice">
        <strong>Correction non publiée.</strong>
        Le détail de la correction (bonnes réponses et appréciations) sera publié
        @if($revealMoment)
        le {{ $revealMoment->format('d/m/Y à H:i') }}.
        @else
        après la clôture de l'évaluation.
        @endif
        Tant que d'autres candidats peuvent encore composer, l'annoncer ici reviendrait à leur
        donner les réponses. Votre note, elle, figure ci-dessus ; conservez le lien de suivi
        ci-dessous pour revenir lire la correction à cette date.
    </div>
    @endif

    {{-- Le lien de suivi voyage dans le document que l'étudiant garde : c'est ce
         qui lui permet de revenir voir sa note définitive, sans rien
         réinstaller ni se reconnecter. --}}
    <div class="section follow">
        <h2 class="section-title"><span class="accent"></span>Suivre votre résultat</h2>
        <table>
            <tr>
                <td class="qr-cell">
                    <img src="{{ $followQr }}" alt="QR code du lien de suivi">
                </td>
                <td class="follow-text">
                    Scannez ce QR code, ou recopiez le lien ci-dessous, pour retrouver ce résultat à tout moment
                    &mdash; et la note définitive une fois les questions rédigées corrigées.
                    @unless($revealsCorrection)
                    Le détail de la correction y apparaîtra dès sa publication.
                    @endunless
                    <span class="follow-link">{{ $followLink->url() }}</span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

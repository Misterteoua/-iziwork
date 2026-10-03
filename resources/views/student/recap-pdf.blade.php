{{--
    Récapitulatif PDF d'un dépôt de travaux.

    Même identité visuelle que le récapitulatif d'évaluation : la mise en page
    est écrite pour dompdf, qui ne connaît ni `grid` ni `flex`. Tout repose donc
    sur des tables et des bordures.

    Deux logos encadrent le document :
      • en haut, le logo de l'établissement (`efsc-recap-pdf.png`) ;
      • en bas de page, le logo Iziwork, dans un pied de page répété sur chaque
        page.

    Les deux sont lus en base64 et de façon tolérante (voir App\Support\PdfAssets) :
    une image absente n'empêche jamais le document de se générer.

    Le contenu est celui d'avant, au caractère près : informations du formulaire,
    informations de l'étudiant, code anonyme, statut et fichiers déposés.
--}}
@php
    $schoolLogo = \App\Support\PdfAssets::dataUri('images/efsc-recap-pdf.png');
    $brandLogo = \App\Support\PdfAssets::dataUri('images/iziwork-logo.png');
    $files = $submission->files;
    // Numéro de document affiché dans le pied de page. La variable est fournie
    // par le contrôleur ; le `?? null` évite une erreur si la vue est rendue
    // seule (tests, aperçus).
    $documentId = $documentId ?? null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Récapitulatif - {{ $form->title }}</title>
    <style>
        /* ------------------------------------------------------------- Base */
        @page { margin: 110px 34px 90px 34px; }

        /* Police « cœur » de dompdf (Helvetica) : rien à embarquer, donc un PDF
           léger. Les accents français et les tirets longs sont dans son encodage. */
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

        /* ----------------------------------------- Bandeau de confirmation */
        .hero {
            margin-top: 20px;
            background: #033299;
            border-radius: 10px;
            padding: 20px 22px;
            color: #ffffff;
            page-break-inside: avoid;
        }

        .hero .hero-title {
            font-size: 16px;
            font-weight: bold;
            color: #ffffff;
        }

        .hero .hero-sub {
            margin-top: 4px;
            font-size: 10px;
            color: #cddcfb;
        }

        /* Le badge de statut apparaît à deux endroits — dans le bandeau et dans
           la fiche de l'étudiant : il est donc défini globalement, et non sous
           `.hero`, sinon la seconde occurrence s'afficherait sans fond. */
        .status {
            display: inline-block;
            padding: 3px 11px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .hero .status { margin-top: 10px; }

        .status-ok { background: #10b981; color: #ffffff; }
        .status-wait { background: #f59e0b; color: #3b2606; }

        /* Repères alignés à droite du bandeau. */
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
            width: 38%;
            font-weight: bold;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .kv .value { color: #1e293b; }

        .mono {
            font-family: "Courier New", Courier, monospace;
        }

        /* ------------------------------------------- Fichiers déposés */
        .file-row td {
            padding: 7px 10px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        .file-row:last-child td { border-bottom: 0; }
        .file-row:nth-child(even) td { background: #f8fafc; }

        .file-row .file-name { color: #1e293b; font-weight: bold; }
        .file-row .file-size { color: #64748b; font-size: 10px; }

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

        <div class="eyebrow">Récapitulatif de dépôt</div>
        <h1>{{ $form->title }}</h1>
    </div>

    {{-- --------------------------------------------- Confirmation du dépôt --}}
    <div class="hero">
        <table>
            <tr>
                <td style="vertical-align: top;">
                    <div class="hero-title">Dépôt enregistré</div>
                    <div class="hero-sub">
                        {{ $submission->anonymous_code
                            ? 'Votre dépôt a bien été reçu sous le code anonyme ci-contre.'
                            : 'Votre dépôt a bien été reçu.' }}
                    </div>
                    <div>
                        <span class="status {{ $submission->status === 'validated' ? 'status-ok' : 'status-wait' }}">
                            {{ $submission->status === 'validated' ? 'Validé' : 'En attente' }}
                        </span>
                    </div>
                </td>
                <td style="vertical-align: top; width: 46%;">
                    <table class="facts">
                        @if($submission->anonymous_code)
                        <tr>
                            <td class="fact-label">Code anonyme</td>
                            <td class="fact-value mono">{{ $submission->anonymous_code }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="fact-label">Fichiers</td>
                            <td class="fact-value">{{ $files->count() }}</td>
                        </tr>
                        <tr>
                            <td class="fact-label">Dépôt</td>
                            <td class="fact-value">{{ $submission->created_at->format('d/m/Y à H:i') }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- ------------------------------------------------------ Le formulaire --}}
    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Informations du formulaire</h2>
        <table class="kv">
            <tr>
                <td class="key">Titre</td>
                <td class="value">{{ $form->title }}</td>
            </tr>
            @if($form->description)
            <tr>
                <td class="key">Description</td>
                <td class="value">{{ $form->description }}</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- ------------------------------------------------------- L'étudiant --}}
    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Informations de l'étudiant</h2>
        <table class="kv">
            @foreach($form->fields as $field)
                @php
                    $fieldName = $field->getFieldName();
                    $value = $field->field_type === 'file' ? null : ($submission->$fieldName ?? null);
                    if ($field->field_type === 'checkbox') {
                        $value = $value ? 'Oui' : 'Non';
                    }
                @endphp
                @if($value)
                <tr>
                    <td class="key">{{ $field->field_label }}</td>
                    <td class="value">{{ $value }}</td>
                </tr>
                @endif
            @endforeach
            <tr>
                <td class="key">Date de soumission</td>
                <td class="value">{{ $submission->created_at->format('d/m/Y à H:i') }}</td>
            </tr>
            @if($submission->anonymous_code)
            <tr>
                <td class="key">Code anonyme</td>
                <td class="value"><span class="mono">{{ $submission->anonymous_code }}</span></td>
            </tr>
            @endif
            <tr>
                <td class="key">Statut</td>
                <td class="value">
                    <span class="status {{ $submission->status === 'validated' ? 'status-ok' : 'status-wait' }}">
                        {{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}
                    </span>
                </td>
            </tr>
        </table>
    </div>

    {{-- --------------------------------------------------- Fichiers déposés --}}
    @if($files->count() > 0)
    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Fichiers déposés ({{ $files->count() }})</h2>
        <table>
            @foreach($files as $file)
            <tr class="file-row">
                <td class="file-name">{{ $file->original_name }}</td>
                <td class="file-size" style="width: 22%; text-align: right;">{{ $file->formatted_size }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif
</body>
</html>

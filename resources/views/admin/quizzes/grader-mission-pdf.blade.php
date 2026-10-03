{{--
    Fiche de mission d'un correcteur externe, à imprimer et remettre en main
    propre.

    Même identité visuelle que les récapitulatifs : mise en page écrite pour
    dompdf (ni `grid`, ni `flex` — tout repose sur des tables et des bordures).

    Deux logos encadrent le document :
      • en haut, le logo de l'établissement (`efsc-recap-pdf.png`) ;
      • en bas de page, le logo Iziwork, dans un pied de page répété sur chaque
        page.

    Les deux sont lus en base64 et de façon tolérante (voir App\Support\PdfAssets) :
    une image absente n'empêche jamais le document de se générer.

    Le point de sécurité ne bouge pas : la référence n'est imprimée que si on le
    demande (`$withReference`), parce qu'une fiche portant à la fois le lien et
    la clé ouvrirait l'accès à elle seule.
--}}
@php
    $schoolLogo = \App\Support\PdfAssets::dataUri('images/efsc-recap-pdf.png');
    $brandLogo = \App\Support\PdfAssets::dataUri('images/iziwork-logo.png');
    // Numéro de document affiché dans le pied de page. La variable est fournie
    // par le contrôleur ; le `?? null` évite une erreur si la vue est rendue
    // seule (tests, aperçus).
    $documentId = $documentId ?? null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche de correction - {{ $grader->name }}</title>
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

        /* ------------------------------------------- Bandeau de la mission */
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
            width: 34%;
            font-weight: bold;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .kv .value { color: #1e293b; }
        .kv .hint { color: #64748b; font-size: 10px; }

        .link {
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            color: #033299;
            font-weight: bold;
            word-break: break-all;
        }

        .reference {
            font-family: "Courier New", Courier, monospace;
            font-size: 20px;
            letter-spacing: 4px;
            color: #033299;
            font-weight: bold;
        }

        /* Une section courte ne doit pas se couper : le titre resterait sur une
           page et sa valeur sur la suivante. Les sections longues, elles,
           peuvent se répartir — dompdf retombe alors sur un découpage normal. */
        .section.keep { page-break-inside: avoid; }

        /* ------------------------------------------------- Encadré d'alerte */
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

        /* ------------------------------------------- Lien personnel (QR) */
        .qr-cell { width: 160px; padding: 0 16px 0 0; vertical-align: top; border: 0; }
        .qr-cell img {
            width: 150px;
            height: 150px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px;
        }
        .qr-text { vertical-align: top; border: 0; color: #475569; }

        /* -------------------------------------------------- Les étapes */
        .steps { margin: 0; padding-left: 18px; }
        .steps li { margin-bottom: 6px; color: #334155; }
        .steps strong { color: #0f172a; }

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
        <div>
            Document généré automatiquement par Iziwork — {{ now()->format('d/m/Y à H:i') }}
        </div>
        @if($documentId)
        <div>Numéro de document : <span class="doc-id">{{ $documentId }}</span></div>
        @endif
        <div>Ne transmettez ce document qu'à la personne nommée ci-dessus.</div>
    </div>

    {{-- --------------------------------------------------- En-tête (école) --}}
    <div class="masthead">
        <div class="rule"></div>

        @if($schoolLogo)
        <div class="school-logo">
            <img src="{{ $schoolLogo }}" alt="EFSC">
        </div>
        @endif

        <div class="eyebrow">Fiche de mission — correction d'évaluation</div>
        <h1>{{ $grader->name }}</h1>
    </div>

    {{-- --------------------------------------------------- La mission --}}
    <div class="hero">
        <table>
            <tr>
                <td style="vertical-align: top;">
                    <div class="hero-title">{{ $quiz->title }}</div>
                    <div class="hero-sub">
                        Vous êtes affecté à la correction des copies de cette évaluation.
                    </div>
                </td>
                <td style="vertical-align: top; width: 46%;">
                    <table class="facts">
                        <tr>
                            <td class="fact-label">Correcteur</td>
                            <td class="fact-value">{{ $grader->name }}</td>
                        </tr>
                        <tr>
                            <td class="fact-label">Échéance</td>
                            <td class="fact-value">
                                @if($grader->expires_at)
                                    {{ $grader->expires_at->format('d/m/Y à H:i') }}
                                @else
                                    Aucune
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- ------------------------------------------------- Votre mission --}}
    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Votre mission</h2>
        <table class="kv">
            <tr><td class="key">Correcteur</td><td class="value">{{ $grader->name }}</td></tr>
            <tr><td class="key">Adresse email</td><td class="value">{{ $grader->email }}</td></tr>
            <tr><td class="key">Évaluation</td><td class="value">{{ $quiz->title }}</td></tr>
            <tr>
                <td class="key">Échéance</td>
                <td class="value">
                    @if($grader->expires_at)
                        {{ $grader->expires_at->format('d/m/Y à H:i') }}
                        <span class="hint">(l'accès se ferme automatiquement à cette date)</span>
                    @else
                        Aucune échéance fixée
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Le lien et son QR code. L'image est calculée côté serveur, en haute
         résolution : elle reste lisible après impression et photocopie. --}}
    <div class="section keep">
        <h2 class="section-title"><span class="accent"></span>Votre lien personnel</h2>
        <table>
            <tr>
                <td class="qr-cell">
                    <img src="{{ $qr }}" alt="QR code du lien de correction">
                </td>
                <td class="qr-text">
                    Scannez ce QR code avec votre téléphone, ou recopiez l'adresse ci-dessous dans un navigateur.
                    <br><br>
                    <span class="link">{{ $link }}</span>
                </td>
            </tr>
        </table>
    </div>

    @if($withReference)
    <div class="section keep">
        <h2 class="section-title"><span class="accent"></span>Votre référence</h2>
        <p style="margin:0 0 8px;">À saisir avec votre email pour ouvrir vos copies :</p>
        <p class="reference">{{ $grader->reference }}</p>
    </div>

    <div class="notice">
        <strong>Remise en main propre.</strong>
        Cette fiche porte à la fois le lien et la référence : elle ouvre donc l'accès aux copies.
        Remettez-la en main propre, et non par un canal partagé.
    </div>
    @else
    <div class="notice">
        <strong>Référence transmise séparément.</strong>
        La référence de correction ne figure volontairement pas sur cette fiche : une fiche qui
        contiendrait le lien <em>et</em> la clé ouvrirait l'accès à elle seule.
    </div>
    @endif

    <div class="section">
        <h2 class="section-title"><span class="accent"></span>Comment procéder</h2>
        <ol class="steps">
            <li>Ouvrez le lien ci-dessus (ou scannez le QR code).</li>
            <li>Saisissez votre email et votre référence de correction.</li>
            <li>Corrigez chaque copie : la note, puis un commentaire si vous le souhaitez — l'étudiant le lira une fois sa copie entièrement corrigée.</li>
            <li>Le bouton <strong>Enregistrer et passer à la suivante</strong> enchaîne les copies sans repasser par la liste.</li>
            <li>Le bouton <strong>Mes corrections (CSV)</strong> exporte vos notes et vos commentaires, et rien d'autre.</li>
        </ol>
    </div>
</body>
</html>

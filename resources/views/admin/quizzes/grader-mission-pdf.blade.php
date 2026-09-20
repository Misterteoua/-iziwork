<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche de correction - {{ $grader->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #333; }
        .header { background: #033299; color: white; padding: 24px; text-align: center; border-radius: 8px; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header p { margin: 8px 0 0; opacity: 0.85; }
        .header .logo { background: #ffffff; display: inline-block; padding: 8px 14px; border-radius: 8px; }
        .header .logo img { display: block; height: 30px; width: 116px; }
        .section { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 18px; }
        .section h2 { font-size: 14px; color: #374151; margin: 0 0 12px; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td:first-child { font-weight: bold; color: #6b7280; width: 34%; }
        .link { font-family: "Courier New", monospace; font-size: 12px; color: #033299; font-weight: bold; word-break: break-all; }
        .reference { font-family: "Courier New", monospace; font-size: 20px; letter-spacing: 4px; color: #033299; font-weight: bold; }
        .notice { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; font-size: 11px; }
        .steps { margin: 0; padding-left: 18px; }
        .steps li { margin-bottom: 6px; }
        .footer { text-align: center; color: #9ca3af; font-size: 10px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/iziwork-logo.png'))) }}" alt="Iziwork">
        </div>
        <p>Fiche de mission — correction d'évaluation</p>
    </div>

    <div class="section">
        <h2>Votre mission</h2>
        <table>
            <tr><td>Correcteur</td><td>{{ $grader->name }}</td></tr>
            <tr><td>Adresse email</td><td>{{ $grader->email }}</td></tr>
            <tr><td>Évaluation</td><td>{{ $quiz->title }}</td></tr>
            <tr>
                <td>Échéance</td>
                <td>
                    @if($grader->expires_at)
                        {{ $grader->expires_at->format('d/m/Y à H:i') }}
                        <span style="color:#6b7280;">(l'accès se ferme automatiquement à cette date)</span>
                    @else
                        Aucune échéance fixée
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Le lien et son QR code. L'image est calculée côté serveur, en haute
         résolution : elle reste lisible après impression et photocopie. --}}
    <div class="section">
        <h2>Votre lien personnel</h2>
        <table>
            <tr>
                <td style="width: 160px; border: 0; padding: 0 16px 0 0;">
                    <img src="{{ $qr }}" alt="QR code du lien de correction" style="width: 150px; height: 150px;">
                </td>
                <td style="border: 0; padding: 0;">
                    Scannez ce QR code avec votre téléphone, ou recopiez l'adresse ci-dessous dans un navigateur.
                    <br><br>
                    <span class="link">{{ $link }}</span>
                </td>
            </tr>
        </table>
    </div>

    @if($withReference)
    <div class="section">
        <h2>Votre référence</h2>
        <p style="margin:0 0 8px;">À saisir avec votre email pour ouvrir vos copies :</p>
        <p class="reference">{{ $grader->reference }}</p>
    </div>

    <div class="notice">
        Cette fiche porte à la fois le lien et la référence : elle ouvre donc l'accès aux copies.
        Remettez-la en main propre, et non par un canal partagé.
    </div>
    @else
    <div class="notice">
        La référence de correction vous est transmise séparément : elle n'est volontairement pas imprimée
        ici, car une fiche qui contiendrait le lien <em>et</em> la clé ouvrirait l'accès à elle seule.
    </div>
    @endif

    <div class="section">
        <h2>Comment procéder</h2>
        <ol class="steps">
            <li>Ouvrez le lien ci-dessus (ou scannez le QR code).</li>
            <li>Saisissez votre email et votre référence de correction.</li>
            <li>Corrigez chaque copie : la note, puis un commentaire si vous le souhaitez — l'étudiant le lira une fois sa copie entièrement corrigée.</li>
            <li>Le bouton <strong>Enregistrer et passer à la suivante</strong> enchaîne les copies sans repasser par la liste.</li>
            <li>Le bouton <strong>Mes corrections (CSV)</strong> exporte vos notes et vos commentaires, et rien d'autre.</li>
        </ol>
    </div>

    <div class="footer">
        <p>Document généré automatiquement par Iziwork — {{ now()->format('d/m/Y à H:i') }}</p>
        <p>Ne transmettez ce document qu'à la personne nommée ci-dessus.</p>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Récapitulatif - {{ $form->title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 10px 0 0;
            opacity: 0.8;
        }
        .section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .section h2 {
            font-size: 16px;
            color: #374151;
            margin: 0 0 15px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        td:first-child {
            font-weight: bold;
            color: #6b7280;
            width: 40%;
        }
        .footer {
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .badge {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Iziwork</h1>
        <p>Récapitulatif de dépôt</p>
    </div>

    <div class="section">
        <h2>Informations du formulaire</h2>
        <table>
            <tr>
                <td>Titre</td>
                <td>{{ $form->title }}</td>
            </tr>
            @if($form->description)
            <tr>
                <td>Description</td>
                <td>{{ $form->description }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="section">
        <h2>Informations de l'étudiant</h2>
        <table>
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
                    <td>{{ $field->field_label }}</td>
                    <td>{{ $value }}</td>
                </tr>
                @endif
            @endforeach
            <tr>
                <td>Date de soumission</td>
                <td>{{ $submission->created_at->format('d/m/Y à H:i') }}</td>
            </tr>
            @if($submission->anonymous_code)
            <tr>
                <td>Code anonyme</td>
                <td>{{ $submission->anonymous_code }}</td>
            </tr>
            @endif
            <tr>
                <td>Statut</td>
                <td><span class="badge">{{ $submission->status === 'validated' ? 'Validée' : 'En attente' }}</span></td>
            </tr>
        </table>
    </div>

    @if($submission->files->count() > 0)
    <div class="section">
        <h2>Fichiers déposés ({{ $submission->files->count() }})</h2>
        <table>
            @foreach($submission->files as $file)
            <tr>
                <td>{{ $file->original_name }}</td>
                <td>{{ $file->formatted_size }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    <div class="footer">
        <p>Ce document a été généré automatiquement par Iziwork</p>
        <p>{{ now()->format('d/m/Y à H:i') }}</p>
    </div>
</body>
</html>

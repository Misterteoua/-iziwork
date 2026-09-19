<?php

namespace App\Support\Import;

/**
 * Conversion d'une liste d'étudiants (Excel, Word, CSV) en fiches candidats.
 *
 * Moins strict que la feuille de questions : l'en-tête est reconnu s'il est là,
 * sinon les colonnes sont prises dans l'ordre (nom, email, filière). Un
 * enseignant qui colle simplement une colonne de noms doit pouvoir générer des
 * références sans rien reformater.
 */
final class StudentRoster
{
    public const MAX_STUDENTS = 1000;

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: array<int, array{name: string, email: ?string, major: ?string}>, 1: ImportOutcome}
     */
    public static function parse(array $rows): array
    {
        $outcome = new ImportOutcome;
        $students = [];
        $columns = ['name' => 0, 'email' => 1, 'major' => 2];
        $headerRead = false;
        $seen = [];

        foreach ($rows as $index => $row) {
            if (self::isEmpty($row)) {
                continue;
            }

            if (! $headerRead) {
                $headerRead = true;
                $detected = self::headerColumns($row);

                if ($detected !== null) {
                    $columns = $detected;

                    continue;
                }
            }

            $line = $index + 1;
            $name = self::clean($row[$columns['name']] ?? '');

            if ($name === '') {
                $outcome->ignored++;

                continue;
            }

            $email = self::clean($row[$columns['email']] ?? '');
            $major = self::clean($row[$columns['major']] ?? '');

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $outcome->addError($line, 'l\'adresse « '.$email.' » n\'est pas valide.');

                continue;
            }

            // Un même étudiant présent deux fois recevrait deux références, dont
            // une seule serait utilisée : on n'en génère qu'une.
            $key = mb_strtolower($name.'|'.$email);

            if (isset($seen[$key])) {
                $outcome->addError($line, $name.' figure déjà dans ce fichier.');

                continue;
            }

            $seen[$key] = true;
            $students[] = [
                'name' => $name,
                'email' => $email === '' ? null : mb_strtolower($email),
                'major' => $major === '' ? null : $major,
            ];

            if (count($students) >= self::MAX_STUDENTS) {
                $outcome->addError(0, 'Seules les '.self::MAX_STUDENTS.' premières lignes ont été importées.');

                break;
            }
        }

        $outcome->imported = count($students);

        if ($students === [] && $outcome->errors === []) {
            throw new ImportException('Aucun nom d\'étudiant trouvé dans ce fichier. Mettez un nom par ligne, ou utilisez le modèle téléchargeable.');
        }

        return [$students, $outcome];
    }

    /**
     * @param  array<int, string>  $row
     * @return array{name: int, email: int, major: int}|null
     */
    private static function headerColumns(array $row): ?array
    {
        $mapping = ['name' => null, 'email' => null, 'major' => null];

        foreach ($row as $index => $cell) {
            $header = self::clean($cell);

            if ($header === '') {
                continue;
            }

            if ($mapping['name'] === null && preg_match('/^(nom|name|étudiant|etudiant|candidat|prénom|prenom)/iu', $header) === 1) {
                $mapping['name'] = (int) $index;

                continue;
            }

            if ($mapping['email'] === null && preg_match('/mail|courriel/i', $header) === 1) {
                $mapping['email'] = (int) $index;

                continue;
            }

            if ($mapping['major'] === null && preg_match('/fili|major|classe|spé|spe|niveau/i', $header) === 1) {
                $mapping['major'] = (int) $index;
            }
        }

        if ($mapping['name'] === null) {
            return null;
        }

        return [
            'name' => $mapping['name'],
            'email' => $mapping['email'] ?? 1,
            'major' => $mapping['major'] ?? 2,
        ];
    }

    /**
     * @param  array<int, string>  $row
     */
    private static function isEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function clean(?string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

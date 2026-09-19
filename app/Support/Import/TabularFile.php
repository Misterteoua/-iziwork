<?php

namespace App\Support\Import;

use Throwable;

/**
 * Point d'entrée unique de lecture : choisit le lecteur selon l'extension et
 * met toutes les lignes sous la même forme (tableau de colonnes).
 *
 * Dernier service rendu ici : une ligne écrite en un seul bloc (« Question ? |
 * A | B | C | B | 2 »), ce que produisent Word et un copier-coller depuis un
 * tableur, est découpée en colonnes. Sans ça, l'enseignant devrait deviner que
 * seul un vrai tableau est accepté.
 */
final class TabularFile
{
    /** Extensions acceptées, et ce qu'on en dit dans le message d'erreur. */
    public const ACCEPTED = ['xlsx', 'docx', 'csv', 'txt'];

    /**
     * Extensions qui exigent l'extension ZIP de PHP : un .xlsx et un .docx sont
     * des archives ZIP. Sur un hébergement où `zip` n'est pas active, le message
     * doit le dire, plutôt que de laisser une erreur technique incompréhensible.
     */
    private const ARCHIVE_EXTENSIONS = ['xlsx', 'docx'];

    /**
     * @return array<int, array<int, string>>
     */
    public static function rows(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (in_array($extension, self::ARCHIVE_EXTENSIONS, true) && ! class_exists(\ZipArchive::class)) {
            throw new ImportException(
                'L\'extension ZIP de PHP n\'est pas active sur ce serveur : elle est nécessaire pour lire les fichiers Excel et Word. '.
                'Activez-la (cPanel → MultiPHP Manager → Options PHP), ou importez un fichier .csv ou .txt.'
            );
        }

        try {
            $rows = match ($extension) {
                'xlsx' => XlsxReader::rows($path),
                'docx' => DocxReader::rows($path),
                'csv', 'txt' => self::delimitedRows($path),
                'xls' => throw new ImportException('L\'ancien format .xls n\'est pas lisible. Ouvrez le fichier dans votre tableur puis « Enregistrer sous » au format .xlsx.'),
                'doc' => throw new ImportException('L\'ancien format .doc n\'est pas lisible. Ouvrez le fichier dans Word puis « Enregistrer sous » au format .docx.'),
                default => throw new ImportException('Format non pris en charge. Utilisez un fichier .xlsx, .docx, .csv ou .txt.'),
            };
        } catch (ImportException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Un fichier corrompu ne doit jamais remonter une trace technique
            // jusqu'à l'écran : le message doit dire quoi faire.
            throw new ImportException('Ce fichier n\'a pas pu être lu. Vérifiez qu\'il s\'ouvre bien dans Excel ou Word, puis réessayez.');
        }

        return self::expandSingleCellRows($rows);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function delimitedRows(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $delimiter = self::detectDelimiter($lines);

        $rows = [];

        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $rows[] = array_map(
                static fn ($cell): string => trim((string) $cell),
                str_getcsv((string) $line, $delimiter, '"', '\\')
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private static function detectDelimiter(array $lines): string
    {
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $counts = [];

            foreach (["\t", ';', '|', ','] as $candidate) {
                $counts[$candidate] = substr_count((string) $line, $candidate);
            }

            arsort($counts);

            $best = array_key_first($counts);

            if (($counts[$best] ?? 0) > 0) {
                return (string) $best;
            }
        }

        return ';';
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<int, string>>
     */
    private static function expandSingleCellRows(array $rows): array
    {
        $expanded = [];

        foreach ($rows as $row) {
            $cells = array_values(array_filter($row, static fn ($cell): bool => $cell !== null));

            if (count($cells) === 1 && self::hasSeparator($cells[0])) {
                $expanded[] = self::splitLine($cells[0]);

                continue;
            }

            $expanded[] = $cells;
        }

        return $expanded;
    }

    private static function hasSeparator(string $value): bool
    {
        return preg_match('/[|\t]/', $value) === 1
            || substr_count($value, ';') >= 2;
    }

    /**
     * @return array<int, string>
     */
    private static function splitLine(string $value): array
    {
        $delimiter = str_contains($value, '|') ? '|' : (str_contains($value, "\t") ? "\t" : ';');

        return array_map(
            static fn (string $cell): string => trim($cell),
            explode($delimiter, $value)
        );
    }
}

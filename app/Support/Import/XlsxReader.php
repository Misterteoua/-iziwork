<?php

namespace App\Support\Import;

use SimpleXMLElement;
use ZipArchive;

/**
 * Lecture d'un fichier Excel .xlsx sans aucune dépendance.
 *
 * Un .xlsx est une archive ZIP contenant du XML : `zip` et `simplexml` sont
 * présents partout, y compris sur l'hébergement mutualisé de production, alors
 * qu'un paquet Composer de plus est précisément ce qui a causé des heures de
 * difficulté lors du premier déploiement. On lit donc le format directement.
 *
 * Le `.xls` (ancien format binaire) n'est pas lisible ainsi : il est refusé avec
 * un message qui dit quoi faire.
 */
final class XlsxReader
{
    /** Au-delà, ce n'est plus un questionnaire : on arrête de lire, sans erreur. */
    public const MAX_ROWS = 3000;

    /**
     * Lignes de la première feuille, chaque ligne étant un tableau de colonnes
     * alignées (les cases vides deviennent des chaînes vides, pour que la
     * colonne « Bonnes réponses » reste à la même place).
     *
     * @return array<int, array<int, string>>
     */
    public static function rows(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ImportException('Le fichier Excel n\'a pas pu être ouvert. S\'il vient d\'un tableur, enregistrez-le de nouveau au format .xlsx.');
        }

        try {
            $sheet = $zip->getFromName(self::firstSheetPath($zip));

            if ($sheet === false) {
                throw new ImportException('Le classeur ne contient aucune feuille lisible.');
            }

            return self::parseSheet($sheet, self::sharedStrings($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');

        if ($raw === false) {
            return [];
        }

        $strings = [];

        foreach (XmlText::parse($raw, 'Le fichier Excel n\'a pas pu être analysé.')->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * Chemin réel de la première feuille.
     *
     * Le nom `xl/worksheets/sheet1.xml` est le plus fréquent, mais rien ne
     * l'impose : on lit donc la table des relations, avec un repli sur la
     * première feuille présente dans l'archive.
     */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false
            && preg_match('/<sheet\b[^>]*r:id="([^"]+)"/', $workbook, $sheet) === 1
            && preg_match('/<Relationship\b[^>]*Id="'.preg_quote($sheet[1], '/').'"[^>]*>/', $rels, $tag) === 1
            && preg_match('/Target="([^"]+)"/', $tag[0], $target) === 1) {
            $path = ltrim(str_replace('../', '', $target[1]), '/');

            return str_starts_with($path, 'xl/') ? $path : 'xl/'.$path;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                return $name;
            }
        }

        throw new ImportException('Le classeur ne contient aucune feuille de calcul.');
    }

    /**
     * @param  array<int, string>  $shared
     * @return array<int, array<int, string>>
     */
    private static function parseSheet(string $sheet, array $shared): array
    {
        $rows = [];
        $sheetData = XmlText::parse($sheet, 'Le fichier Excel n\'a pas pu être analysé.')->sheetData;

        if ($sheetData === null) {
            return [];
        }

        foreach ($sheetData->row as $row) {
            $cells = [];
            $position = 0;

            foreach ($row->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $column = $reference !== '' ? self::columnIndex($reference) : $position;

                $cells[$column] = self::cellValue($cell, $shared);
                $position = $column + 1;
            }

            if ($cells === []) {
                $rows[] = [];

                continue;
            }

            // Ligne « pleine » : une case vide au milieu doit garder sa place
            // pour que les colonnes restent alignées d'une ligne à l'autre.
            $max = max(array_keys($cells));
            $dense = array_fill(0, $max + 1, '');

            foreach ($cells as $column => $value) {
                $dense[$column] = $value;
            }

            $rows[] = $dense;

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $shared
     */
    private static function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            return $shared[(int) $cell->v] ?? '';
        }

        if ($type === 'inlineStr') {
            return self::text($cell->is);
        }

        return trim((string) $cell->v);
    }

    /**
     * Texte d'un noeud, en concaténant ses fragments `<t>`.
     */
    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = '';

        foreach ($node->xpath('.//t') ?: [] as $fragment) {
            $text .= (string) $fragment;
        }

        return trim($text);
    }

    /**
     * « C12 » → 2 (index de colonne à partir de zéro).
     */
    private static function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/i', $reference, $matches);

        $index = 0;

        foreach (str_split(strtoupper($matches[1] ?? 'A')) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

}

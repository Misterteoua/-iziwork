<?php

namespace App\Support\Import;

use ZipArchive;

/**
 * Fabrication d'un modèle Excel à remplir, téléchargeable depuis l'application.
 *
 * Le modèle est le vrai remède au format : sans lui, chaque enseignant invente
 * une disposition et découvre les règles par des erreurs d'import.
 *
 * Le fichier est écrit à la main (ZIP + XML) plutôt qu'avec une bibliothèque :
 * générer un xlsx valide ne demande qu'une poignée de fichiers, et cela évite
 * d'ajouter une dépendance Composer à un projet déployé sur cPanel sans SSH.
 * Les chaînes sont écrites en « inline strings », ce qui supprime le fichier
 * partagé `sharedStrings.xml` et donc une source de corruption.
 */
final class QuizTemplate
{
    /**
     * @param  array<int, array<int, string>>  $rows
     */
    public static function xlsx(array $rows, string $sheetName = 'Feuille1'): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportException(
                'L\'extension ZIP de PHP n\'est pas active sur ce serveur : elle est nécessaire pour fabriquer le modèle Excel. '.
                'Activez-la (cPanel → MultiPHP Manager → Options PHP).'
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'iziwork-modele');

        if ($path === false) {
            throw new ImportException('Le modèle n\'a pas pu être préparé sur le serveur.');
        }

        $zip = new ZipArchive;

        // CREATE est obligatoire en plus d'OVERWRITE : sans lui, ZipArchive
        // refuse d'écrire et le modèle ne serait jamais produit.
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ImportException('Le modèle n\'a pas pu être préparé sur le serveur.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($rows));
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * Modèle de questions : l'en-tête attendu par l'import, puis quatre exemples
     * qui montrent le choix unique, le choix multiple, le barème décimal, et la
     * question ouverte.
     *
     * La colonne « Type » est facultative : un fichier qui ne l'a pas s'importe
     * selon les règles d'origine. Elle est présente dans le modèle parce que
     * c'est le seul moyen d'écrire une question rédigée — sans elle, une ligne
     * sans bonne réponse est une erreur, pas une question ouverte.
     *
     * @return array<int, array<int, string>>
     */
    public static function questionRows(): array
    {
        return [
            ['Question', 'Type', 'A', 'B', 'C', 'D', 'E', 'F', 'Bonnes réponses', 'Points'],
            ['Quelle est la capitale de la Côte d\'Ivoire ?', 'QCM', 'Abidjan', 'Yamoussoukro', 'Bouaké', '', '', '', 'B', '1'],
            ['Quelles structures sont des files de priorité ?', 'QCM', 'Tas binaire', 'Pile', 'Tas de Fibonacci', 'Liste chaînée', '', '', 'A C', '2'],
            ['Vrai ou faux : PHP est compilé.', 'QCM', 'Vrai', 'Faux', '', '', '', '', '2', '0,5'],
            ['Expliquez en deux ou trois lignes le processus de fabrication du savon.', 'Ouvert', '', '', '', '', '', '', '', '4'],
        ];
    }

    /**
     * Modèle de liste d'étudiants.
     *
     * @return array<int, array<int, string>>
     */
    public static function studentRows(): array
    {
        return [
            ['Nom', 'Email', 'Filière'],
            ['Curie Marie', 'marie.curie@example.com', 'MPI'],
            ['Turing Alan', 'alan.turing@example.com', 'ISI'],
            ['Kouassi Awa', '', ''],
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';

            foreach ($row as $columnIndex => $value) {
                if ($value === '') {
                    continue;
                }

                $reference = self::columnName($columnIndex).($rowIndex + 1);
                $xml .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'
                    .htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    .'</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function columnName(int $index): string
    {
        $name = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr($i % 26 + 65).$name;
        }

        return $name;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }
}

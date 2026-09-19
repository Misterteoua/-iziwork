<?php

namespace App\Support\Import;

use SimpleXMLElement;
use ZipArchive;

/**
 * Les préfixes d'espace de noms (`<w:p>` et non `<p>`) rendent l'accès direct
 * muet : {@see XmlText} s'en charge avant toute lecture.
 */

/**
 * Lecture d'un fichier Word .docx sans dépendance.
 *
 * Un .docx est lui aussi une archive ZIP : `word/document.xml` contient le
 * texte. Deux dispositions sont acceptées, parce que les deux se rencontrent :
 *
 *   - un tableau (une ligne par question, une cellule par proposition) ;
 *   - des paragraphes, une question par ligne, propositions séparées par `|`
 *     ou par une tabulation.
 *
 * Le `.doc` ancien format est refusé : il faut l'enregistrer en `.docx`.
 */
final class DocxReader
{
    public const MAX_ROWS = 3000;

    /**
     * @return array<int, array<int, string>>
     */
    public static function rows(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ImportException('Le fichier Word n\'a pas pu être ouvert. Enregistrez-le au format .docx.');
        }

        try {
            $document = $zip->getFromName('word/document.xml');

            if ($document === false) {
                throw new ImportException('Ce fichier n\'est pas un document Word .docx. Enregistrez-le de nouveau dans ce format.');
            }
        } finally {
            $zip->close();
        }

        $body = XmlText::parse($document, 'Le document Word n\'a pas pu être analysé.')->body;

        if ($body === null) {
            return [];
        }

        $rows = [];

        foreach ($body->children() as $name => $node) {
            if ($name === 'p') {
                $rows[] = [self::paragraph($node)];
            } elseif ($name === 'tbl') {
                foreach ($node->tr as $tr) {
                    $cells = [];

                    foreach ($tr->tc as $tc) {
                        $cells[] = self::paragraph($tc);
                    }

                    $rows[] = $cells;
                }
            }

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Texte d'un paragraphe : les fragments `<t>` recollés, corrigés des
     * espaces insécables que Word insère avant les deux-points.
     */
    private static function paragraph(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = '';

        foreach ($node->xpath('.//t') ?: [] as $fragment) {
            $text .= (string) $fragment;
        }

        return trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $text));
    }

}

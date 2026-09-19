<?php

namespace App\Support\Import;

use SimpleXMLElement;

/**
 * Neutralisation des espaces de noms XML.
 *
 * SimpleXML et les espaces de noms sont une source de faux négatifs redoutable :
 * `$xml->row` sur un `<row>` en espace de noms par défaut, ou `$xml->body` sur un
 * `<w:body>` préfixé, renvoient tous deux du vide alors que la donnée est bien
 * là. Un import qui ne trouve rien mais ne signale rien est le pire des
 * résultats : l'enseignant croit son fichier importé.
 *
 * On retire donc les déclarations puis les préfixes à l'intérieur des balises.
 * Les deux formats qui nous intéressent (SpreadsheetML, WordprocessingML)
 * n'utilisent qu'un espace de noms par document : aucune collision n'est
 * possible. Le contenu textuel n'est jamais touché (le remplacement n'a lieu
 * qu'entre `<` et `>`), donc une adresse ou un horaire ne peut pas être abîmé.
 */
final class XmlText
{
    public static function stripNamespaces(string $raw): string
    {
        $raw = (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $raw);

        $stripped = preg_replace_callback('/<[^>]*>/', static function (array $tag): string {
            $tag = (string) preg_replace('/(<\/?)[\w.\-]+:/', '$1', $tag[0]);

            return (string) preg_replace('/\s[\w.\-]+:/', ' ', $tag);
        }, $raw);

        return $stripped ?? $raw;
    }

    /**
     * Analyse un document XML aux espaces de noms neutralisés.
     */
    public static function parse(string $raw, string $failureMessage): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(self::stripNamespaces($raw));

            if ($xml === false) {
                throw new ImportException($failureMessage);
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

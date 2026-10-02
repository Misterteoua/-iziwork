<?php

namespace App\Support\Import;

/**
 * Découpage d'un énoncé qui contient plusieurs « Question N ».
 *
 * Un cas pratique arrive souvent dans une seule cellule : un contexte, puis
 * « Question 1 … Question 2 … Question 3 … » collés à la suite. Importé tel
 * quel, il ne fait qu'une question — le candidat répond à la première et les
 * autres disparaissent du barème. Le découpage en fait autant de questions
 * distinctes, chacune précédée du même contexte, parce que le contexte est
 * nécessaire pour répondre à chacune.
 *
 * Les repères sont volontairement stricts, comme ceux de la mise en forme
 * ({@see \App\Support\QuestionText}) : un faux positif scinderait un énoncé que
 * personne n'a demandé à découper. Un repère ne compte donc que s'il commence
 * une phrase — au début du texte, après un retour à la ligne, ou après un « ; »,
 * un « . » ou un « : ». Une phrase qui cite « la Question 1 du sujet précédent »
 * n'en est pas un.
 */
final class QuestionSplitter
{
    /** Il faut au moins deux repères : un seul « Question 1 » n'est pas un découpage. */
    private const MIN_HEADINGS = 2;

    /** « Question 5 », jamais « question 5 » : la casse évite les citations. */
    private const HEADING_PATTERN = '/Question\s+(\d+)/u';

    /**
     * Une ligne qui ne contient qu'une coupure : « --- », « *** », « ___ ». C'est
     * l'enseignant qui la pose, pour dire où couper une question en deux.
     */
    private const CUT_PATTERN = '/^[ \t]*(?:-{3,}|\*{3,}|_{3,})[ \t]*$/mu';

    /**
     * Ce qui doit précéder un repère pour qu'il commence une phrase.
     *
     * Le « : » en fait partie : un sujet écrit volontiers « Répondez aux
     * questions suivantes : Question 1 … ». Le texte qui précède devient alors
     * le contexte recopié, deux-points compris.
     */
    private const SEPARATORS = [';', '.', ':', "\n"];

    /**
     * @param  int  $maxLength  longueur maximale d'une question produite
     * @return array{chapeau: string, questions: array<int, string>}|null  null si l'énoncé n'est pas découpable
     */
    public static function split(string $label, int $maxLength = 2000): ?array
    {
        $headings = self::headings($label);

        if (count($headings) < self::MIN_HEADINGS) {
            return null;
        }

        // Les numéros se suivent dans l'ordre : « Question 1 » puis « Question 3 »
        // est un découpage, « Question 3 » puis « Question 1 » est une citation.
        $previous = null;

        foreach ($headings as $heading) {
            if ($previous !== null && $heading['number'] <= $previous) {
                return null;
            }

            $previous = $heading['number'];
        }

        $chapeau = self::trimSeparator(trim(substr($label, 0, $headings[0]['offset'])));
        $total = count($headings);
        $questions = [];

        foreach ($headings as $index => $heading) {
            $start = $heading['offset'] + $heading['length'];
            $end = $index + 1 < $total ? $headings[$index + 1]['offset'] : strlen($label);

            $content = self::trimSeparator(trim(substr($label, $start, $end - $start)));

            // Un repère sans contenu (« Question 1 Question 2 … ») n'est pas un
            // découpage : mieux vaut ne rien faire que produire une question vide.
            if ($content === '') {
                return null;
            }

            $question = $chapeau === '' ? $content : $chapeau."\n\n".$content;

            // Une question produite reste soumise à la même limite que la saisie
            // manuelle : le contexte recopié peut la faire dépasser.
            if (mb_strlen($question) > $maxLength) {
                return null;
            }

            $questions[] = $question;
        }

        return ['chapeau' => $chapeau, 'questions' => $questions];
    }

    /**
     * Découpe un énoncé aux coupures que l'enseignant y a posées.
     *
     * C'est le geste inverse de la recousure, et il sert à séparer deux questions
     * que l'analyse avait laissées ensemble — parce qu'aucun « Question N » ne les
     * distinguait. La coupure est une ligne de tirets, d'astérisques ou de
     * soulignés, seule sur sa ligne : elle est retirée du texte, elle ne se lit
     * pas dans l'énoncé final.
     *
     * @return array<int, string>  les morceaux, ou une liste vide s'il n'y a pas de coupure
     */
    public static function cut(string $label): array
    {
        if (preg_match(self::CUT_PATTERN, $label) !== 1) {
            return [];
        }

        $parts = [];

        foreach (preg_split(self::CUT_PATTERN, $label) ?: [] as $part) {
            $part = trim($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        // Une seule coupure en fin de texte ne sépare rien : il faut au moins
        // deux morceaux pour parler d'un découpage.
        return count($parts) >= 2 ? $parts : [];
    }

    /**
     * Recoud deux énoncés que le découpage avait séparés.
     *
     * Le contexte recopié en tête de chaque question est retiré de la seconde
     * avant la recousure : sans cela, fusionner « Question 1 » et « Question 2 »
     * donnerait deux fois le même chapeau. Le reste est joint par une ligne
     * vide, donc deux paragraphes à l'affichage.
     */
    public static function join(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        $leftBlocks = preg_split('/\n{2,}/', $left) ?: [$left];
        $rightBlocks = preg_split('/\n{2,}/', $right) ?: [$right];

        // Le premier bloc identique est le contexte : il ne se répète pas.
        if (count($rightBlocks) > 1 && trim((string) $leftBlocks[0]) === trim((string) $rightBlocks[0])) {
            array_shift($rightBlocks);
        }

        $rest = implode("\n\n", array_map('trim', $rightBlocks));

        return $rest === '' ? $left : $left."\n\n".$rest;
    }

    /**
     * Repères « Question N » qui commencent une phrase, avec leur position.
     *
     * @return array<int, array{offset: int, length: int, number: int}>
     */
    private static function headings(string $label): array
    {
        if (preg_match_all(self::HEADING_PATTERN, $label, $matches, PREG_OFFSET_CAPTURE) < self::MIN_HEADINGS) {
            return [];
        }

        $headings = [];

        foreach ($matches[0] as $index => $match) {
            $offset = (int) $match[1];

            if (! self::startsSentence($label, $offset)) {
                continue;
            }

            $headings[] = [
                'offset' => $offset,
                'length' => strlen($match[0]),
                'number' => (int) $matches[1][$index][0],
            ];
        }

        return $headings;
    }

    /**
     * Le repère commence-t-il une phrase ? Le début du texte, un retour à la
     * ligne, un « ; » ou un « . » le précèdent — rien d'autre.
     */
    private static function startsSentence(string $label, int $offset): bool
    {
        $before = rtrim(substr($label, 0, $offset), " \t");

        return $before === '' || in_array(substr($before, -1), self::SEPARATORS, true);
    }

    /**
     * Le « ; » qui séparait les morceaux n'a plus rien à séparer une fois le
     * découpage fait ; le « . » reste, lui, parce qu'il termine une phrase.
     */
    private static function trimSeparator(string $text): string
    {
        return rtrim($text, " ;,\t");
    }
}

<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Mise en forme d'un texte d'évaluation, au moment de l'affichage : l'énoncé,
 * le guide de correction, la copie rendue par l'étudiant et l'appréciation du
 * correcteur — tous suivent la même règle, un texte long doit se lire.
 *
 * Un énoncé arrive souvent du fichier importé comme une seule ligne : les
 * retours à la ligne de la cellule ont été écrasés, et les énumérations
 * (« a) … b) … », « 1. 2. », « - … ») se retrouvent noyées dans un pavé. Le
 * candidat doit lire la question, pas la déchiffrer.
 *
 * Rien n'est jamais réécrit en base : la mise en forme est un rendu. Elle
 * s'applique donc aux questions déjà enregistrées comme aux suivantes, et une
 * présentation maladroite reste cosmétique — jamais une donnée perdue.
 *
 * Le résultat est du HTML sûr : chaque fragment de texte est échappé, et seules
 * les balises produites ici (paragraphes, listes) traversent la vue.
 *
 * Les repères sont volontairement prudents, parce qu'un faux positif déforme un
 * énoncé sans que personne ne l'ait demandé :
 *   - un marqueur isolé n'ouvre pas de liste (il faut au moins deux éléments) ;
 *   - les lettres et les numéros doivent se suivre (a, b, c… / 1, 2, 3…) ;
 *   - « 2.5 », « N°1 », « 12,5 point(s) », « QSE) », « etc. » ne sont pas des
 *     marqueurs : il faut une lettre seule (a à h) ou un nombre suivi d'un
 *     espace, jamais collés à un mot.
 */
final class QuestionText
{
    /** Une énumération ne se reconnaît qu'à partir de deux éléments. */
    private const MIN_ITEMS = 2;

    private const LETTER = 'letter';

    private const NUMBER = 'number';

    private const BULLET = 'bullet';

    /** Lettre puis parenthèse ou point : « a) », « B. ». */
    private const LETTER_PATTERN = '/(?<![\p{L}\p{N}])([a-hA-H])[).][ \t]*/u';

    /** Numéro puis point ou parenthèse : « 1. », « 2) ». L'espace qui suit est
     *  exigé, sinon « 2.5 » et « le 12. » deviendraient des marqueurs. */
    private const NUMBER_PATTERN = '/(?<![\p{L}\p{N}_.,])(\d{1,2})[).][ \t]+/u';

    /** Puce : tiret, tiret cadratin, demi-cadratin, puce ou astérisque, en début
     *  de ligne ou après un séparateur (« ; -le manuel », « comme suit : - … »). */
    private const BULLET_PATTERN = '/(?<![^\s;:])[-–—•*][ \t]*/u';

    /** « ; Question 5 » ou « . Question 5 » : le repère d'un nouveau cas
     *  pratique commence une phrase, il ne termine pas l'énumération précédente.
     *  Le point et le point-virgule sont conservés (lookbehind), seuls les
     *  espaces séparent. */
    private const HEADING_PATTERN = '/(?<=[;.])\s*(?=Question\s+\d)/u';

    /**
     * HTML de l'énoncé : paragraphes et listes, texte échappé.
     *
     * `$prefix` (le numéro de la question, par exemple « 13. ») est placé au
     * début de la première ligne du texte — pour qu'il reste collé à l'énoncé
     * au lieu de flotter sur une ligne à lui.
     */
    public static function html(?string $text, string $prefix = ''): HtmlString
    {
        $blocks = [];
        $paragraph = [];

        foreach (self::lines((string) $text) as $line) {
            if ($line === '') {
                $blocks = array_merge($blocks, self::paragraphBlocks($paragraph));
                $paragraph = [];

                continue;
            }

            $lineBlocks = self::blocks($line);

            // Une ligne sans énumération rejoint le paragraphe en cours : un
            // simple retour à la ligne devient un <br>, pas une respiration
            // supplémentaire.
            if (count($lineBlocks) === 1 && $lineBlocks[0]['type'] === 'text') {
                $paragraph[] = self::entry($line, $lineBlocks[0]['lines'][0]);

                continue;
            }

            $blocks = array_merge($blocks, self::paragraphBlocks($paragraph));
            $paragraph = [];

            foreach ($lineBlocks as $block) {
                $blocks[] = $block;
            }
        }

        $blocks = array_merge($blocks, self::paragraphBlocks($paragraph));
        $blocks = array_values(array_filter($blocks, static fn (?array $block): bool => $block !== null));

        if ($blocks === []) {
            return new HtmlString('');
        }

        // Le numéro de la question reste en gris, comme avant : c'est un repère
        // de lecture, pas le début de l'énoncé.
        $number = trim($prefix) === '' ? '' : '<span class="qt-index">'.e(trim($prefix)).'</span> ';
        $html = '';

        foreach ($blocks as $position => $block) {
            $lead = $position === 0 ? $number : '';

            $html .= $block['type'] === 'list'
                ? self::listHtml($block, $lead)
                : self::paragraphHtml($block['lines'], $lead);
        }

        return new HtmlString($html);
    }

    // ------------------------------------------------------------- Découpage

    /**
     * Lignes normalisées : fins de ligne, espaces insécables, espaces répétés.
     *
     * @return array<int, string>
     */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\u{2009}"], ' ', $text);

        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);

            // Deux lignes vides d'affilée ne séparent pas davantage : on veut
            // des paragraphes, pas des trous dont personne ne comprend l'origine.
            if ($line === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }

            $lines[] = $line;
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Une ligne devient des blocs : des paragraphes et des listes.
     *
     * @return array<int, array{type: string, lines?: array<int, string>, kind?: string, items?: array<int, string>}>
     */
    private static function blocks(string $line): array
    {
        $items = self::items($line);
        $total = count($items);
        $blocks = [];
        $pending = '';
        $index = 0;

        while ($index < $total) {
            $run = self::runEnd($items, $index);

            if ($run > $index) {
                $blocks[] = self::textBlock([$pending]);
                $pending = '';

                $blocks[] = [
                    'type' => 'list',
                    'kind' => $items[$index]['kind'],
                    'start' => self::position($items[$index]['kind'], $items[$index]['marker']),
                    'items' => array_map(
                        static fn (array $member): string => self::itemText($member['text']),
                        array_slice($items, $index, $run - $index)
                    ),
                ];

                $index = $run;

                continue;
            }

            $item = $items[$index];

            // Un « a) » après une fin de phrase rouvre une instruction : il
            // commence un paragraphe au lieu de poursuivre le précédent.
            if ($item['break'] && trim($pending) !== '') {
                $blocks[] = self::textBlock([$pending]);
                $pending = '';
            }

            $pending = self::append($pending, $item);

            $index++;
        }

        $blocks[] = self::textBlock([$pending]);

        return array_values(array_filter($blocks, static fn (?array $block): bool => $block !== null));
    }

    /**
     * Morceaux de la ligne : le texte, puis chaque marqueur avec ce qui le suit.
     *
     * @return array<int, array{kind: string, marker: string, text: string}>
     */
    private static function chunks(string $line): array
    {
        $matches = [];

        foreach ([self::LETTER, self::NUMBER, self::BULLET] as $kind) {
            if (preg_match_all(self::patterns()[$kind], $line, $found, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($found[0] as $match) {
                $matches[] = [
                    'kind' => $kind,
                    'start' => (int) $match[1],
                    'end' => (int) $match[1] + strlen($match[0]),
                    'marker' => trim((string) preg_replace('/\s+/u', ' ', $match[0])),
                ];
            }
        }

        usort($matches, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $chunks = [];
        $cursor = 0;

        foreach ($matches as $match) {
            // Deux motifs ne doivent pas se disputer le même caractère.
            if ($match['start'] < $cursor) {
                continue;
            }

            $chunks[] = ['kind' => 'text', 'marker' => '', 'text' => substr($line, $cursor, $match['start'] - $cursor)];
            $chunks[] = ['kind' => $match['kind'], 'marker' => $match['marker'], 'text' => ''];
            $cursor = $match['end'];
        }

        $chunks[] = ['kind' => 'text', 'marker' => '', 'text' => substr($line, $cursor)];

        return $chunks;
    }

    /**
     * Éléments de la ligne : le texte et les marqueurs, chacun avec son contenu.
     *
     * `lead` retient ce qui précédait le marqueur dans la source : il faut
     * savoir si « N°1 » s'écrivait collé, pour ne pas le rendre « N° 1 » en
     * recollant le marqueur rejeté à son texte.
     *
     * @return array<int, array{kind: string, marker: string, text: string, break: bool, lead: string}>
     */
    private static function items(string $line): array
    {
        $items = [];
        $current = null;
        $lead = '';

        foreach (self::chunks($line) as $chunk) {
            if ($chunk['kind'] === 'text') {
                $lead = $chunk['text'];

                if ($current === null) {
                    if (trim($chunk['text']) !== '') {
                        $items[] = self::textItem($chunk['text'], false, $chunk['text']);
                    }

                    continue;
                }

                $items[$current]['text'] .= $chunk['text'];

                continue;
            }

            $items[] = [
                'kind' => $chunk['kind'],
                'marker' => $chunk['marker'],
                'text' => '',
                'break' => self::startsFamily($chunk) && str_ends_with(rtrim($lead), '.'),
                'lead' => $lead,
            ];

            $current = count($items) - 1;
        }

        // Un marqueur sans contenu ne se transforme pas en ligne vide : il se
        // recolle là où il était écrit. C'est le cas de « management N°1. » et
        // de « 1. 2. », où le marqueur appartient à la phrase précédente.
        $merged = [];

        foreach ($items as $item) {
            if ($item['kind'] === 'text' || trim($item['text']) !== '') {
                $merged[] = $item;

                continue;
            }

            if ($merged === []) {
                $merged[] = self::textItem($item['marker'], $item['break'], $item['lead']);

                continue;
            }

            $last = count($merged) - 1;
            $merged[$last]['text'] = self::concat($merged[$last]['text'], $item['marker'], $item['lead']);
        }

        return self::splitHeadings($merged);
    }

    /**
     * Coupe devant « ; Question 3 » : le cas pratique suivant commence là.
     *
     * @param  array<int, array{kind: string, marker: string, text: string, break: bool, lead: string}>  $items
     * @return array<int, array{kind: string, marker: string, text: string, break: bool, lead: string}>
     */
    private static function splitHeadings(array $items): array
    {
        $split = [];

        foreach ($items as $item) {
            $parts = preg_split(self::HEADING_PATTERN, $item['text']) ?: [$item['text']];

            if (count($parts) < 2) {
                $split[] = $item;

                continue;
            }

            foreach ($parts as $position => $part) {
                // Le séparateur qui précédait le titre n'a plus rien à séparer.
                $part = rtrim(trim($part), ' ;,');

                if ($position === 0) {
                    // Un contenu vide ne doit pas effacer le marqueur : « d) ;
                    // Question 4 » ne peut pas perdre son « d) ».
                    $split[] = $part === ''
                        ? self::textItem($item['marker'], $item['break'], $item['lead'])
                        : ['kind' => $item['kind'], 'marker' => $item['marker'], 'text' => $part, 'break' => $item['break'], 'lead' => $item['lead']];

                    continue;
                }

                if ($part !== '') {
                    $split[] = self::textItem($part, true, ' ');
                }
            }
        }

        return array_values(array_filter($split, static fn (array $item): bool => trim($item['text']) !== '' || $item['kind'] !== 'text'));
    }

    /**
     * Fin d'une énumération valide, ou l'index de départ si ce n'en est pas une.
     *
     * @param  array<int, array{kind: string, marker: string, text: string, break: bool, lead: string}>  $items
     */
    private static function runEnd(array $items, int $start): int
    {
        $kind = $items[$start]['kind'];

        if ($kind === 'text') {
            return $start;
        }

        $end = $start;
        $previous = null;

        while ($end < count($items) && $items[$end]['kind'] === $kind) {
            // Les puces n'ont pas d'ordre ; les lettres et les numéros, si.
            if ($kind === self::BULLET) {
                $end++;

                continue;
            }

            $position = self::position($kind, $items[$end]['marker']);

            if ($position === null || ($previous !== null && $position !== $previous + 1)) {
                break;
            }

            $previous = $position;
            $end++;
        }

        // La liste s'arrête au premier élément qui ne suit plus, sans perdre
        // ceux qui la précèdent : « a) b) c) d) b) » donne une liste de quatre.
        return $end - $start >= self::MIN_ITEMS ? $end : $start;
    }

    /**
     * Rang du marqueur dans sa famille : 0 pour « a) » et « 1. », 1 pour « b) »…
     */
    private static function position(string $kind, string $marker): ?int
    {
        if ($kind === self::LETTER) {
            $letter = strtolower(substr($marker, 0, 1));

            return preg_match('/^[a-h]$/', $letter) === 1 ? ord($letter) - 97 : null;
        }

        if ($kind === self::NUMBER) {
            $value = (int) $marker;

            return $value >= 1 ? $value - 1 : null;
        }

        return null;
    }

    /** Le marqueur ouvre-t-il sa famille (« a) », « 1. ») ? Lui seul peut
     *  commencer un nouveau paragraphe après un point. */
    private static function startsFamily(array $chunk): bool
    {
        if ($chunk['kind'] === self::LETTER) {
            $letter = substr($chunk['marker'], 0, 1);

            return $letter === 'a' || $letter === 'A';
        }

        if ($chunk['kind'] === self::NUMBER) {
            return (int) $chunk['marker'] === 1;
        }

        return false;
    }

    // -------------------------------------------------------------- Assemblage

    /**
     * Une ligne de paragraphe : son texte, et de quoi rejoindre une liste.
     *
     * Une ligne qui n'est qu'un marqueur et son contenu (« 1. Les documents »)
     * peut faire partie d'une énumération écrite ligne par ligne ; les autres
     * ne font que du texte.
     *
     * @return array{text: string, list: ?array{kind: string, position: int, item: string}}
     */
    private static function entry(string $line, string $text): array
    {
        $items = self::items($line);

        if (count($items) !== 1 || $items[0]['kind'] === 'text' || trim($items[0]['lead']) !== '') {
            return ['text' => $text, 'list' => null];
        }

        $item = $items[0];
        $position = self::position($item['kind'], $item['marker']);
        $content = self::itemText($item['text']);

        if ($position === null || $content === '') {
            return ['text' => $text, 'list' => null];
        }

        return ['text' => $text, 'list' => ['kind' => $item['kind'], 'position' => $position, 'item' => $content]];
    }

    /**
     * Les lignes d'un paragraphe, avec les listes qu'elles forment ensemble.
     *
     * C'est ici que « 1. Les documents » puis « 2. Les procédures », écrits
     * chacun sur sa ligne, redeviennent une liste — sans rien changer au cas
     * où les lignes ne se suivent pas, où le retour à la ligne suffit.
     *
     * @param  array<int, array{text: string, list: ?array}>  $entries
     * @return array<int, array{type: string, lines?: array<int, string>, kind?: string, items?: array<int, string>}>
     */
    private static function paragraphBlocks(array $entries): array
    {
        $blocks = [];
        $lines = [];
        $index = 0;
        $total = count($entries);

        while ($index < $total) {
            $run = self::listRunEnd($entries, $index);

            if ($run > $index) {
                $blocks = array_merge($blocks, array_filter([self::textBlock($lines)]));
                $lines = [];
                $items = [];

                for ($position = $index; $position < $run; $position++) {
                    $items[] = $entries[$position]['list']['item'];
                }

                $blocks[] = [
                    'type' => 'list',
                    'kind' => $entries[$index]['list']['kind'],
                    'start' => $entries[$index]['list']['position'],
                    'items' => $items,
                ];
                $index = $run;

                continue;
            }

            $lines[] = $entries[$index]['text'];
            $index++;
        }

        return array_merge($blocks, array_filter([self::textBlock($lines)]));
    }

    /**
     * Fin d'une liste écrite sur plusieurs lignes, ou l'index de départ si les
     * lignes ne forment pas une énumération valide.
     *
     * @param  array<int, array{text: string, list: ?array}>  $entries
     */
    private static function listRunEnd(array $entries, int $start): int
    {
        $kind = $entries[$start]['list']['kind'] ?? null;

        if ($kind === null) {
            return $start;
        }

        $end = $start;
        $previous = null;

        while ($end < count($entries) && ($entries[$end]['list']['kind'] ?? null) === $kind) {
            $position = $entries[$end]['list']['position'];

            if ($kind !== self::BULLET && $previous !== null && $position !== $previous + 1) {
                break;
            }

            $previous = $position;
            $end++;
        }

        return $end - $start >= self::MIN_ITEMS ? $end : $start;
    }

    /**
     * Les lignes d'un même paragraphe voyagent ensemble : c'est ce qui permet à
     * un retour à la ligne écrit par l'enseignant de rester un <br>.
     *
     * @param  array<int, string>  $lines
     * @return array{type: string, lines: array<int, string>}|null
     */
    private static function textBlock(array $lines): ?array
    {
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? null : ['type' => 'text', 'lines' => $lines];
    }

    /**
     * @return array{kind: string, marker: string, text: string, break: bool, lead: string}
     */
    private static function textItem(string $text, bool $break, string $lead = ''): array
    {
        return ['kind' => 'text', 'marker' => '', 'text' => $text, 'break' => $break, 'lead' => $lead];
    }

    /**
     * Ajoute un élément au texte en cours.
     *
     * @param  array{kind: string, marker: string, text: string, break: bool, lead: string}  $item
     */
    private static function append(string $pending, array $item): string
    {
        $text = trim($item['text']);
        $fragment = $item['kind'] === 'text' ? $text : trim($item['marker'].' '.$text);

        return self::concat($pending, $fragment, $item['lead']);
    }

    /**
     * Colle un ajout à un texte existant.
     *
     * Un marqueur rejeté se recolle tel qu'il était écrit : « N°1 » ne devient
     * pas « N° 1 », mais « 1. 2. » garde son espace. `$lead` est ce qui
     * précédait le marqueur dans la source — c'est lui qui dit s'il était collé.
     */
    private static function concat(string $left, string $right, string $lead): string
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        if (trim($lead) !== '' && ! str_ends_with($lead, ' ') && ! str_ends_with($lead, "\t")) {
            return $left.$right;
        }

        return $left.' '.$right;
    }

    /**
     * Texte d'un élément de liste : le « ; » qui séparait les éléments d'une
     * énumération n'a plus rien à séparer une fois la liste faite.
     */
    private static function itemText(string $text): string
    {
        return rtrim(trim($text), ' ;,');
    }

    /**
     * Colle deux fragments de texte d'une même ligne.
     */
    private static function glue(string $left, string $right): string
    {
        $left = rtrim($left);
        $right = ltrim($right);

        if ($left === '') {
            return $right;
        }

        return $right === '' ? $left : $left.' '.$right;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  string  $number  numéro de la question, déjà en HTML (échappé)
     */
    private static function paragraphHtml(array $lines, string $number = ''): string
    {
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        if ($lines === []) {
            return '';
        }

        $html = '';

        foreach ($lines as $index => $line) {
            $html .= ($index === 0 ? $number : '<br>').e($line);
        }

        return '<p class="qt-p">'.$html.'</p>';
    }

    /**
     * @param  array{kind: string, items: array<int, string>}  $block
     * @param  string  $number  numéro de la question, déjà en HTML (échappé)
     */
    private static function listHtml(array $block, string $number = ''): string
    {
        $tag = $block['kind'] === self::BULLET ? 'ul' : 'ol';

        $class = match ($block['kind']) {
            self::BULLET => 'qt-list qt-bullets',
            self::NUMBER => 'qt-list qt-numbered',
            default => 'qt-list qt-alpha',
        };

        // Une énumération qui commence à « c) » doit rester « c) » : la
        // renuméroter d'office ferait mentir l'énoncé, qui renvoie parfois à
        // « la réponse c) ».
        $start = $tag === 'ol' && ($block['start'] ?? 0) > 0 ? ' start="'.($block['start'] + 1).'"' : '';

        // Le type est écrit deux fois : la classe pour le navigateur et le PDF,
        // l'attribut pour les moteurs qui ne lisent pas la feuille de style.
        $opening = $tag === 'ol' && $block['kind'] === self::LETTER
            ? '<ol type="a"'.$start.' class="'.$class.'">'
            : '<'.$tag.$start.' class="'.$class.'">';

        $items = '';

        foreach ($block['items'] as $index => $item) {
            $items .= '<li>'.($index === 0 ? $number : '').e($item).'</li>';
        }

        return $opening.$items.'</'.$tag.'>';
    }

    /**
     * @return array<string, string>
     */
    private static function patterns(): array
    {
        return [
            self::LETTER => self::LETTER_PATTERN,
            self::NUMBER => self::NUMBER_PATTERN,
            self::BULLET => self::BULLET_PATTERN,
        ];
    }
}

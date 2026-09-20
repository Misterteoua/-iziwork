<?php

namespace App\Support;

/**
 * Code d'un lien court : huit caractères tirés au sort.
 *
 * L'alphabet omet O, 0, I, 1 et l : un code se dicte au téléphone et se retape
 * sans ambiguïté. 57^8, soit environ 1,1 × 10^14 combinaisons, tirées par
 * random_int (donc par le générateur du système) : deviner le lien d'un
 * étudiant en essayant des codes n'est pas une stratégie praticable — et les
 * essais infructueux sont comptés à l'arrivée (voir ShortLinkController).
 */
final class ShortCode
{
    public const LENGTH = 8;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /** Motif accepté par la route /l/{code}. */
    public const PATTERN = '[A-Za-z0-9]{4,16}';

    public static function generate(): string
    {
        $code = '';
        $highest = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < self::LENGTH; $index++) {
            $code .= self::ALPHABET[random_int(0, $highest)];
        }

        return $code;
    }
}

<?php

namespace App\Support;

use App\Models\QuizAttempt;
use Illuminate\Support\Str;

/**
 * Génère les références d'évaluation : 10 caractères alphanumériques.
 *
 * L'alphabet exclut volontairement les caractères ambigus (0/O, 1/I/L) : une
 * référence est recopiée à la main par un étudiant, souvent sur un téléphone en
 * salle d'examen, et une confusion entre O et 0 le laisserait dehors.
 */
final class QuizReference
{
    /** Longueur d'une référence. */
    public const LENGTH = 10;

    /** Alphabet sans 0, O, 1, I ni L. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Référence réellement unique en base.
     *
     * L'unicité est garantie par l'index de la colonne : on retire tant que la
     * référence est prise, plutôt que de risquer une exception de contrainte au
     * milieu de la génération d'une centaine de références.
     *
     * Chaque caractère est tiré individuellement dans l'alphabet : projeter un
     * tirage ASCII par modulo introduirait un biais, et sortirait de l'alphabet
     * dès que la taille de celui-ci ne divise pas le modulo.
     */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;

        do {
            $reference = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $reference .= self::ALPHABET[random_int(0, $last)];
            }
        } while (QuizAttempt::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Normalise puis valide une référence saisie par un étudiant.
     *
     * Les espaces sont retirés et la casse ignorée : un étudiant tapera
     * « ab cd ef gh ij » ou en minuscules sans y penser. Retourne null si la
     * saisie ne peut pas être une référence.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::upper(preg_replace('/\s+/', '', $value) ?? '');

        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $value) === 1
            ? $value
            : null;
    }
}

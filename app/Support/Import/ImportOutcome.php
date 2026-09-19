<?php

namespace App\Support\Import;

/**
 * Compte rendu d'un import : ce qui est passé, et surtout ce qui a été refusé.
 *
 * Un import qui n'annonce pas ses lignes refusées est un piège : l'enseignant
 * croit avoir chargé cinquante questions et l'épreuve en comporte quarante-sept,
 * découvert le jour de l'examen.
 */
final class ImportOutcome
{
    /**
     * @param  array<int, string>  $errors  messages horodatés par leur numéro de ligne
     */
    public function __construct(
        public int $imported = 0,
        public array $errors = [],
        public int $ignored = 0,
    ) {}

    public function addError(int $line, string $message): void
    {
        // Le numéro de ligne affiché est celui du tableur, pas de l'index de
        // tableau : un décalage d'une unité ici ferait chercher au mauvais endroit.
        $this->errors[] = $line > 0 ? 'Ligne '.$line.' : '.$message : $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Premières erreurs seulement : au-delà de dix, le fichier a un problème de
     * format, et afficher les cent autres ne fait que noyer le vrai message.
     *
     * @return array<int, string>
     */
    public function shownErrors(int $limit = 10): array
    {
        return array_slice($this->errors, 0, $limit);
    }

    public function hiddenErrorsCount(int $limit = 10): int
    {
        return max(0, count($this->errors) - $limit);
    }

    /**
     * Phrase de compte rendu, accordée au singulier ou au pluriel.
     *
     * Les deux formes sont fournies par l'appelant : « 1 question importée » et
     * « 3 questions importées » ne se déduisent pas l'une de l'autre sans
     * connaître le genre du mot, et un « 1 question importé » ferait désordre
     * dans l'écran d'un enseignant.
     */
    public function summary(string $singular, string $plural): string
    {
        $parts = [$this->imported.' '.($this->imported > 1 ? $plural : $singular)];

        if ($this->ignored > 0) {
            $parts[] = $this->ignored.' ligne(s) ignorée(s)';
        }

        if ($this->hasErrors()) {
            $parts[] = count($this->errors).' erreur(s) à corriger';
        }

        return implode(' · ', $parts);
    }
}

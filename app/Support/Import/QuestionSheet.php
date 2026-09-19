<?php

namespace App\Support\Import;

use App\Support\QuizQuestionData;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Conversion d'une feuille (Excel, Word, CSV) en questions d'évaluation.
 *
 * L'en-tête est exigé : sans lui, rien ne distingue « la colonne E est une
 * cinquième proposition » de « la colonne E contient la bonne réponse ». Deviner
 * reviendrait à créer des questions fausses en silence, ce qui ne se découvre
 * qu'après l'épreuve. Un fichier sans en-tête reconnaissable est donc refusé avec
 * un message qui renvoie au modèle téléchargeable.
 *
 * Deux familles de questions cohabitent :
 *   - les questions à propositions (« radio », « checkbox »), dont le type se
 *     déduit du nombre de bonnes réponses désignées ;
 *   - les questions ouvertes (« textarea »), déclarées par une colonne « Type »
 *     valant « Ouvert ». Une question ouverte n'a ni proposition ni bonne
 *     réponse : si l'une des deux est renseignée, la ligne est refusée plutôt que
 *     corrigée en silence, parce qu'un fichier qui se contredit signale le plus
 *     souvent une erreur de colonne.
 *
 * Sans colonne « Type », le comportement historique s'applique ligne pour ligne —
 * les fichiers préparés avant cette évolution s'importent donc exactement comme
 * avant.
 *
 * Les propositions vides sont conservées pendant l'analyse (pour que « la bonne
 * réponse est C » vise bien la colonne C), puis retirées par
 * {@see QuizQuestionData::normalize()} qui remappe les bonnes réponses.
 */
final class QuestionSheet
{
    /** Au-delà, le fichier relève de la banque de questions, pas d'un import. */
    public const MAX_QUESTIONS = 300;

    /** Types de questions acceptés dans la colonne « Type ». */
    private const KIND_OPEN = 'open';

    private const KIND_QCM = 'qcm';

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: ImportOutcome}
     */
    public static function parse(array $rows): array
    {
        $outcome = new ImportOutcome;
        $questions = [];
        $columns = null;

        foreach ($rows as $index => $row) {
            $line = $index + 1;

            if (self::isEmpty($row)) {
                continue;
            }

            if ($columns === null) {
                $columns = self::headerColumns($row);

                if ($columns === null) {
                    throw new ImportException(
                        'La première ligne doit être un en-tête (une colonne « Question », des colonnes A, B, C…, puis « Bonnes réponses » et « Points »). '.
                        'Téléchargez le modèle pour partir d\'un fichier au bon format.'
                    );
                }

                continue;
            }

            $question = self::questionFromRow($row, $columns, $line, $outcome);

            if ($question === null) {
                continue;
            }

            $questions[] = $question;

            if (count($questions) >= self::MAX_QUESTIONS) {
                $outcome->addError(0, 'Seules les '.self::MAX_QUESTIONS.' premières questions ont été importées : découpez le fichier.');

                break;
            }
        }

        if ($questions === [] && $outcome->errors === []) {
            throw new ImportException('Aucune question trouvée dans ce fichier. Vérifiez que le fichier suit le modèle téléchargeable.');
        }

        return [$questions, $outcome];
    }

    /**
     * Colonnes déduites de l'en-tête.
     *
     * La colonne « Type » est facultative. Quand elle est présente, elle rend le
     * fichier lisible même s'il ne contient que des questions ouvertes : il n'y a
     * alors ni colonne de propositions utilisable, ni bonne réponse à désigner.
     *
     * @param  array<int, string>  $row
     * @return array{question: int, options: array<int, int>, correct: ?int, points: ?int, type: ?int}|null
     */
    private static function headerColumns(array $row): ?array
    {
        $first = self::clean($row[0] ?? '');

        if (preg_match('/question|énoncé|enonce|intitulé|intitule/i', $first) !== 1) {
            return null;
        }

        $options = [];
        $correct = null;
        $points = null;
        $type = null;

        foreach ($row as $index => $cell) {
            if ($index === 0) {
                continue;
            }

            $header = self::clean($cell);

            if ($header === '') {
                continue;
            }

            // « Type », « Nature » : ce qui distingue une question rédigée d'un QCM.
            if ($type === null && preg_match('/^(type|nature)\b/iu', $header) === 1) {
                $type = (int) $index;

                continue;
            }

            if ($points === null && preg_match('/point|barème|bareme|note|score/i', $header) === 1) {
                $points = (int) $index;

                continue;
            }

            if ($correct === null && preg_match('/correct|bonne|juste|vraie/i', $header) === 1) {
                $correct = (int) $index;

                continue;
            }

            // « A », « Option B », « Réponse C » : une colonne de proposition.
            if (preg_match('/(^|\s)[A-H]$/u', $header) === 1) {
                $options[] = (int) $index;
            }
        }

        // Sans colonne « Type », les règles d'avant s'appliquent : il faut de quoi
        // écrire une question à propositions.
        if ($type === null && (count($options) < 2 || $correct === null)) {
            return null;
        }

        if (count($options) > QuizQuestionData::MAX_OPTIONS) {
            $options = array_slice($options, 0, QuizQuestionData::MAX_OPTIONS);
        }

        return [
            'question' => 0,
            'options' => $options,
            'correct' => $correct,
            'points' => $points,
            'type' => $type,
        ];
    }

    /**
     * @param  array<int, string>  $row
     * @param  array{question: int, options: array<int, int>, correct: ?int, points: ?int, type: ?int}  $columns
     * @return array<string, mixed>|null
     */
    private static function questionFromRow(array $row, array $columns, int $line, ImportOutcome $outcome): ?array
    {
        $label = self::clean($row[$columns['question']] ?? '');

        if ($label === '') {
            // Ligne sans énoncé : très souvent une ligne vide au milieu du
            // tableur. Ce n'est pas une erreur, seulement une ligne ignorée.
            $outcome->ignored++;

            return null;
        }

        $kind = self::KIND_QCM;

        if ($columns['type'] !== null) {
            $kind = self::declaredKind(self::clean($row[$columns['type']] ?? ''), $line, $outcome);

            if ($kind === null) {
                return null;
            }
        }

        $options = [];

        foreach ($columns['options'] as $column) {
            $options[] = self::clean($row[$column] ?? '');
        }

        $points = self::parsePoints($row, $columns, $line, $outcome);

        if ($points === false) {
            return null;
        }

        try {
            // QuizQuestionData tranche les règles de fond dans les deux cas : une
            // question importée ne peut pas être acceptée là où la saisie manuelle
            // la refuserait.
            if ($kind === self::KIND_OPEN) {
                $question = self::openQuestion($label, $options, $row, $columns, $line, $outcome, $points);
            } else {
                $question = self::choiceQuestion($label, $options, $row, $columns, $line, $outcome, $points);
            }
        } catch (ValidationException $e) {
            $outcome->addError($line, (string) (collect($e->errors())->flatten()->first() ?? 'question invalide.'));

            return null;
        }

        if ($question === null) {
            return null;
        }

        $outcome->imported++;

        return $question;
    }

    /**
     * Type déclaré par la ligne, ou null si la valeur est inutilisable (l'erreur
     * est alors déjà consignée).
     */
    private static function declaredKind(string $cell, int $line, ImportOutcome $outcome): ?string
    {
        if ($cell === '') {
            $outcome->addError($line, 'la colonne « Type » est vide : indiquez « QCM » ou « Ouvert ».');

            return null;
        }

        $value = Str::lower(Str::ascii($cell));

        foreach (['ouvert', 'redig', 'redaction', 'texte', 'libre', 'developpement', 'essai', 'dissertation'] as $needle) {
            if (str_contains($value, $needle)) {
                return self::KIND_OPEN;
            }
        }

        foreach (['qcm', 'choix', 'multiple', 'radio', 'checkbox', 'objectif'] as $needle) {
            if (str_contains($value, $needle)) {
                return self::KIND_QCM;
            }
        }

        $outcome->addError($line, 'le type « '.$cell.' » est inconnu : écrivez « QCM » ou « Ouvert ».');

        return null;
    }

    /**
     * @param  array<int, string>  $options  propositions telles qu'écrites
     * @param  array<int, string>  $row
     * @param  array{question: int, options: array<int, int>, correct: ?int, points: ?int, type: ?int}  $columns
     * @return array<string, mixed>|null
     */
    private static function openQuestion(
        string $label,
        array $options,
        array $row,
        array $columns,
        int $line,
        ImportOutcome $outcome,
        float $points,
    ): ?array {
        $correctCell = $columns['correct'] === null ? '' : self::clean($row[$columns['correct']] ?? '');
        $filled = array_values(array_filter($options, static fn (string $option): bool => $option !== ''));

        if ($filled !== [] || $correctCell !== '') {
            $outcome->addError(
                $line,
                'une question ouverte n\'a pas de propositions ni de bonne réponse : laissez les colonnes A à H et « Bonnes réponses » vides.'
            );

            return null;
        }

        return QuizQuestionData::normalizeOpen($label, $points);
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, string>  $row
     * @param  array{question: int, options: array<int, int>, correct: ?int, points: ?int, type: ?int}  $columns
     * @return array<string, mixed>|null
     */
    private static function choiceQuestion(
        string $label,
        array $options,
        array $row,
        array $columns,
        int $line,
        ImportOutcome $outcome,
        float $points,
    ): ?array {
        if ($columns['correct'] === null) {
            $outcome->addError($line, 'ce fichier n\'a pas de colonne « Bonnes réponses » : impossible de savoir quelle proposition est juste.');

            return null;
        }

        $correct = self::parseCorrect(self::clean($row[$columns['correct']] ?? ''), $options, $line, $outcome);

        if ($correct === null) {
            return null;
        }

        return QuizQuestionData::normalize($label, $options, $correct, $points);
    }

    /**
     * « B », « A C », « 1;3 » → index 0-based des propositions.
     *
     * @param  array<int, string>  $options
     * @return array<int, int>|null
     */
    private static function parseCorrect(string $cell, array $options, int $line, ImportOutcome $outcome): ?array
    {
        if ($cell === '') {
            $outcome->addError($line, 'aucune bonne réponse n\'est désignée.');

            return null;
        }

        $tokens = preg_split('/[\s,;|\/]+/', $cell, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $indexes = [];

        foreach ($tokens as $token) {
            if (preg_match('/^[A-H]$/i', $token) === 1) {
                $index = ord(strtoupper($token)) - 65;
            } elseif (preg_match('/^\d{1,2}$/', $token) === 1) {
                $index = (int) $token - 1;
            } else {
                $outcome->addError($line, 'la bonne réponse « '.$token.' » doit être une lettre (A à H) ou un numéro de proposition.');

                return null;
            }

            if ($index < 0 || $index >= count($options)) {
                $outcome->addError($line, 'la bonne réponse « '.$token.' » ne correspond à aucune proposition de cette ligne.');

                return null;
            }

            $indexes[] = $index;
        }

        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return $indexes === [] ? null : $indexes;
    }

    /**
     * Barème de la ligne : 1 point par défaut, comme avant.
     *
     * Rend `false` quand la valeur est illisible — l'erreur est alors consignée —
     * ce qui évite de confondre « barème invalide » et « barème absent ».
     *
     * @param  array<int, string>  $row
     * @param  array{question: int, options: array<int, int>, correct: ?int, points: ?int, type: ?int}  $columns
     */
    private static function parsePoints(array $row, array $columns, int $line, ImportOutcome $outcome): float|false
    {
        if ($columns['points'] === null) {
            return 1.0;
        }

        $raw = self::clean($row[$columns['points']] ?? '');

        if ($raw === '') {
            return 1.0;
        }

        // Une virgule décimale française est fréquente dans un tableur : la
        // refuser ferait échouer un fichier parfaitement lisible.
        $raw = str_replace(',', '.', $raw);

        if (! is_numeric($raw)) {
            $outcome->addError($line, 'le barème « '.$raw.' » n\'est pas un nombre.');

            return false;
        }

        return (float) $raw;
    }

    /**
     * @param  array<int, string>  $row
     */
    private static function isEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function clean(?string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

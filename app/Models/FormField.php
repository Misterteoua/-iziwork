<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    use HasFactory;

    /**
     * Types de questions corrigées automatiquement.
     *
     * « radio » = une seule réponse attendue, « checkbox » = plusieurs. Ce sont
     * les seuls types pour lesquels une machine peut décider seul : on compare
     * un ensemble d'index cochés à un ensemble attendu.
     */
    public const QUESTION_TYPES = ['radio', 'checkbox'];

    /** Question ouverte : la réponse est un texte, noté à la main. */
    public const OPEN_TYPE = 'textarea';

    /**
     * Types de champ qui sont des questions d'évaluation, corrigées ou non.
     *
     * C'est cette liste — et non QUESTION_TYPES — qui décide quels champs sont
     * des questions : le nombre de questions d'une évaluation, le tirage
     * aléatoire et le barème doivent compter les questions ouvertes, sinon une
     * épreuve entièrement rédigée afficherait « 0 question ».
     */
    public const ANSWER_TYPES = ['radio', 'checkbox', self::OPEN_TYPE];

    protected $fillable = [
        'form_id',
        'field_label',
        'field_type',
        'required',
        'order',
        'options',
        'correct_answer',
        'expected_answer',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
            'correct_answer' => 'array',
            'points' => 'decimal:2',
        ];
    }

    /**
     * Cette question accepte-t-elle plusieurs réponses ?
     */
    public function isMultipleAnswer(): bool
    {
        return $this->field_type === 'checkbox';
    }

    public function isQuestion(): bool
    {
        return in_array($this->field_type, self::ANSWER_TYPES, true);
    }

    /**
     * Question ouverte : réponse rédigée, corrigée par l'enseignant.
     *
     * Aucune comparaison automatique n'est possible sur un texte libre : cette
     * question n'est donc jamais comptée comme juste ou fausse par le correcteur
     * automatique, elle attend une note.
     */
    public function isOpen(): bool
    {
        return $this->field_type === self::OPEN_TYPE;
    }

    /**
     * Index des bonnes réponses, triés.
     *
     * @return array<int, int>
     */
    public function correctIndexes(): array
    {
        $indexes = array_map('intval', array_filter($this->correct_answer ?? [], 'is_numeric'));

        sort($indexes);

        return $indexes;
    }

    /**
     * Comparaison entre le choix d'un candidat et la bonne réponse.
     *
     * Sans objet pour une question ouverte : l'appelant ne l'appelle que pour
     * les questions à propositions.
     *
     * Lot 1 : la réponse est juste si l'ensemble coché correspond exactement à
     * l'ensemble attendu. Pas de demi-point pour une réponse partielle — une
     * seule règle, prévisible et vérifiable par l'étudiant.
     *
     * @param  array<int, int>  $chosen
     */
    public function isCorrectChoice(array $chosen): bool
    {
        $chosen = array_values(array_map('intval', $chosen));
        sort($chosen);

        $expected = $this->correctIndexes();

        return $expected !== [] && $chosen === $expected;
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Get the input name used for this field in the submission form.
     *
     * Each file field sends its uploads under its own name (file_{id}) so the
     * submitted files can be associated back to the field they were uploaded
     * into. The standard student fields (name, email, phone, filière) are
     * matched by their label.
     */
    public function getFieldName(): string
    {
        // File fields use their own input name
        if ($this->field_type === 'file') {
            return 'file_' . $this->id;
        }

        return match (strtolower($this->field_label)) {
            'nom complet', 'nom', 'name' => 'student_name',
            'email', 'adresse email', 'mail', 'adresse mail', 'e-mail', 'adresse e-mail', 'courriel' => 'student_email',
            'téléphone', 'telephone', 'tel', 'phone' => 'student_phone',
            'filière', 'filiere', 'major', 'spécialité' => 'student_major',
            default => 'student_' . strtolower(str_replace(' ', '_', $this->field_label)),
        };
    }

    /**
     * Check if this field is a select (liste déroulante)
     */
    public function isSelect(): bool
    {
        return $this->field_type === 'select';
    }

    /**
     * Check if this field is a checkbox
     */
    public function isCheckbox(): bool
    {
        return $this->field_type === 'checkbox';
    }

    /**
     * Get parsed options for select fields
     */
    public function getOptionsList(): array
    {
        if (!$this->options || !is_array($this->options)) {
            return [];
        }

        return $this->options;
    }
}

<?php

namespace App\Models;

use App\Support\ShortCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Un lien court : `/l/Ab12Cd34` au lieu du jeton de trente-deux caractères.
 *
 * Un lien court ne remplace rien : le lien long continue de fonctionner
 * exactement comme avant. Il est seulement plus court à transmettre, et pour un
 * résultat d'étudiant, il identifie la copie sans dépendre d'une session de
 * navigateur.
 */
class ShortLink extends Model
{
    /** Accès à une évaluation (`/q/{jeton}`). */
    public const KIND_QUIZ = 'quiz';

    /** Accès à un formulaire de dépôt (`/s/{jeton}`). */
    public const KIND_FORM = 'form';

    /** Résultat personnel d'un étudiant. */
    public const KIND_RESULT = 'result';

    protected $fillable = ['code', 'kind', 'form_id', 'quiz_attempt_id'];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public static function forQuiz(Form $quiz): self
    {
        return self::target(self::KIND_QUIZ, $quiz, null);
    }

    public static function forDeposit(Form $form): self
    {
        return self::target(self::KIND_FORM, $form, null);
    }

    public static function forAttempt(Form $quiz, QuizAttempt $attempt): self
    {
        return self::target(self::KIND_RESULT, $quiz, $attempt);
    }

    /**
     * L'adresse complète. Le domaine peut être remplacé par un domaine court
     * dédié (SHORT_LINK_DOMAIN) sans toucher au reste du code.
     */
    public function url(): string
    {
        $domain = config('app.short_link_domain');

        return $domain
            ? rtrim($domain, '/').'/l/'.$this->code
            : url('/l/'.$this->code);
    }

    /**
     * Un nouveau code, l'ancien cessant aussitôt de fonctionner : c'est ainsi
     * qu'on annule un lien de résultat qu'on juge compromis.
     */
    public function rotate(): void
    {
        $this->update(['code' => self::freshCode()]);
    }

    /**
     * Le lien d'une cible, créé à la première demande puis retrouvé tel quel.
     *
     * Un rechargement de page ne doit pas fabriquer un nouveau code : le lien
     * qu'un étudiant a noté doit rester valable.
     */
    private static function target(string $kind, Form $form, ?QuizAttempt $attempt): self
    {
        $find = fn () => static::query()
            ->where('kind', $kind)
            ->where('form_id', $form->getKey())
            ->when(
                $attempt === null,
                fn ($query) => $query->whereNull('quiz_attempt_id'),
                fn ($query) => $query->where('quiz_attempt_id', $attempt->getKey()),
            )
            ->first();

        $existing = $find();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return static::create([
                'code' => self::freshCode(),
                'kind' => $kind,
                'form_id' => $form->getKey(),
                'quiz_attempt_id' => $attempt?->getKey(),
            ]);
        } catch (QueryException $exception) {
            // Deux appels simultanés pour la même cible : le second retrouve la
            // ligne du premier plutôt que d'échouer.
            $raced = $find();

            if ($raced === null) {
                throw $exception;
            }

            return $raced;
        }
    }

    /**
     * Un code libre. Une collision (de l'ordre de 10^-14) n'est pas une raison
     * de montrer une erreur : on retire.
     */
    private static function freshCode(): string
    {
        for ($try = 0; $try < 10; $try++) {
            $code = ShortCode::generate();

            if (! static::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Aucun code de lien court disponible.');
    }
}

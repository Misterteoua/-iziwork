<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;

class AdminResetPassword extends Command
{
    /**
     * Same minimum as the install wizard: a shared local/prod password must
     * not be weaker just because it was set from a terminal.
     */
    private const MIN_LENGTH = 12;

    protected $signature = 'admin:reset-password
                            {--email= : Email of the admin account (defaults to the first admin)}';

    protected $description = 'Réinitialise le mot de passe d\'un compte administrateur (saisie masquée dans le terminal)';

    public function handle(): int
    {
        $email = $this->option('email');

        $user = $email !== null
            ? AdminUser::where('email', $email)->first()
            : AdminUser::orderBy('id')->first();

        if ($user === null) {
            $this->error($email !== null
                ? "Aucun compte administrateur pour « {$email} »."
                : "Aucun compte administrateur n'existe encore (php artisan db:seed pour en créer un).");

            return self::FAILURE;
        }

        $this->line("Réinitialisation du mot de passe pour <info>{$user->email}</info>.");

        $password = $this->askHiddenValidated('Nouveau mot de passe');

        if ($password === null) {
            $this->warn('Annulé — mot de passe inchangé.');

            return self::FAILURE;
        }

        $confirmation = $this->askHiddenValidated('Confirmation');

        if ($confirmation === null) {
            $this->warn('Annulé — mot de passe inchangé.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('Les deux saisies ne correspondent pas — mot de passe inchangé.');

            return self::FAILURE;
        }

        // The model's 'hashed' cast bcrypts the value on save.
        $user->password_hash = $password;
        $user->save();

        $this->info("Mot de passe mis à jour pour {$user->email}.");

        return self::SUCCESS;
    }

    /**
     * Hidden prompt that re-asks until the value satisfies the length rule.
     * Returns null only when the user aborts with Ctrl+C.
     */
    private function askHiddenValidated(string $label): ?string
    {
        $helper = $this->getHelper('question');
        \assert($helper instanceof QuestionHelper);

        $question = new Question("{$label} : ");
        $question->setHidden(true)
            ->setHiddenFallback(false)
            ->setValidator(function (?string $value): string {
                $value ??= '';

                if (mb_strlen($value) < self::MIN_LENGTH) {
                    throw new \RuntimeException(
                        'Au moins '.self::MIN_LENGTH.' caractères (saisie : '.mb_strlen($value).').'
                    );
                }

                return $value;
            })
            // Unlimited attempts would loop forever on a closed/piped stdin
            // (EOF keeps feeding empty strings to the validator). Three tries
            // then Symfony throws MissingInputException, which we catch below.
            ->setMaxAttempts(3);

        try {
            return (string) $helper->ask($this->input, $this->output, $question);
        } catch (\RuntimeException | \Symfony\Component\Console\Exception\ExceptionInterface) {
            // MissingInputException on exhausted input, or the validator's own
            // RuntimeException re-thrown after 3 failed attempts — refuse any
            // fallback, change nothing.
            $this->error('Mot de passe inchangé (saisie interrompue ou invalide après 3 essais).');

            return null;
        }
    }
}

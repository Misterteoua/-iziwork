<?php

namespace App\Http\Controllers;

use App\Support\MigrationReconciliation;
use Dotenv\Dotenv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Assistant d'installation web.
 *
 * Sert à déployer l'application sur un hébergement cPanel où ni SSH ni
 * Terminal ne sont disponibles : au lieu de lancer `composer install` puis
 * `php artisan migrate` en ligne de commande, l'administrateur ouvre une page,
 * saisit les identifiants MySQL et le compte admin, et tout est joué côté PHP.
 *
 * Les dépendances PHP doivent donc être présentes dans l'archive déployée
 * (voir tools/build-release.sh).
 *
 * Sécurité — trois verrous :
 *   1. un fichier de verrou `storage/app/install.lock` : une fois l'installation
 *      terminée, toutes les routes /install renvoient 404 ;
 *   2. un jeton secret lu dans `.install-token` (hors `public/`, donc non
 *      accessible par le web) : sans ce jeton, aucun formulaire n'est affiché ;
 *   3. le jeton est supprimé après succès, rendant toute réutilisation vaine.
 *
 * Le contrôleur est volontairement enregistré HORS du groupe de middlewares
 * « web » (voir routes/install.php) : il doit fonctionner avant que `.env`
 * n'existe, donc sans session, sans cookie chiffré (APP_KEY absente) et sans
 * jeton CSRF. La protection repose sur le jeton d'installation ci-dessus.
 */
class InstallController extends Controller
{
    private const LOCK_RELATIVE_PATH = 'app/install.lock';

    private const TOKEN_RELATIVE_PATH = '.install-token';

    private const TEMPLATE_RELATIVE_PATH = '.env.production.example';

    /**
     * Affiche le formulaire d'installation.
     */
    public function show(Request $request): View
    {
        $this->abortIfAlreadyInstalled();

        return $this->renderForm($this->defaults($request));
    }

    /**
     * Écrit .env, migre la base et crée le compte administrateur.
     */
    public function store(Request $request): View
    {
        $this->abortIfAlreadyInstalled();

        $tokenPath = $this->tokenPath();

        // Verrou n°2 — sans fichier de jeton, on refuse d'aller plus loin.
        if (! is_file($tokenPath)) {
            return $this->renderForm($this->defaults($request), [
                "Fichier de sécurité introuvable : {$tokenPath}",
                "Créez ce fichier depuis le Gestionnaire de fichiers de cPanel, "
                .'placez-y une longue chaîne aléatoire, puis rechargez cette page.',
            ]);
        }

        $expected = trim((string) file_get_contents($tokenPath));

        if ($expected === '' || ! hash_equals($expected, (string) $request->input('install_token'))) {
            return $this->renderForm($this->defaults($request), [
                "Jeton d'installation invalide.",
                "Il doit correspondre exactement au contenu de {$tokenPath}.",
            ]);
        }

        $values = $this->formValues($request);
        $validator = $this->validator($values);

        if ($validator->fails()) {
            return $this->renderForm($values, $validator->errors()->all());
        }

        // 1. Vérifier la connexion MySQL avant d'écrire quoi que ce soit.
        try {
            $this->connectToDatabase($values);
        } catch (Throwable $e) {
            return $this->renderForm($values, [
                'Connexion à la base de données impossible.',
                $e->getMessage(),
                'Vérifiez DB_HOST (essayez 127.0.0.1 si "localhost" échoue), '
                .'le nom de la base, et surtout le mot de passe.',
            ]);
        }

        // 2. Écrire .env.
        try {
            $this->writeEnvFile($values);
        } catch (Throwable $e) {
            return $this->renderForm($values, [
                "Impossible d'écrire le fichier .env.",
                $e->getMessage(),
            ]);
        }

        // 3. Relire .env pour détecter un mot de passe tronqué (piège classique
        //    avec les caractères # ou $) avant de créer le schéma.
        try {
            $this->assertEnvRoundTrip($values);
        } catch (Throwable $e) {
            return $this->renderForm($values, [$e->getMessage()]);
        }

        // 4. Schéma + compte administrateur.
        try {
            Artisan::call('config:clear');

            // Une base créée par setup.sql (SQL brut) laisse la table
            // `migrations` vide : on marque d'abord les migrations déjà
            // effectives, sinon chaque `create_*_table` échoue sur
            // « table already exists ».
            app(MigrationReconciliation::class)->reconcile();

            Artisan::call('migrate', ['--force' => true]);
            $migrations = trim(Artisan::output());

            // Le seeder lit config('admin.*') : on injecte les valeurs du
            // formulaire pour ne pas dépendre d'une relecture de .env.
            config([
                'admin.username' => $values['admin_username'],
                'admin.email' => $values['admin_email'],
                'admin.password' => $values['admin_password'],
            ]);

            Artisan::call('db:seed', ['--force' => true]);
            $seeding = trim(Artisan::output());
        } catch (Throwable $e) {
            return $this->renderForm($values, [
                "Échec pendant les migrations ou la création du compte admin.",
                $e->getMessage(),
                'Le fichier .env a bien été écrit : corrigez la cause puis revalidez ce formulaire.',
            ]);
        }

        // 5. Verrouiller l'installation et neutraliser le jeton.
        $this->lockInstallation($values);
        @unlink($this->tokenPath());

        return view('install.success', [
            'appUrl' => rtrim($values['app_url'], '/'),
            'adminEmail' => $values['admin_email'],
            'migrations' => $migrations,
            'seeding' => $seeding,
        ]);
    }

    /**
     * Verrou n°1 : une fois installée, la page n'existe plus.
     */
    private function abortIfAlreadyInstalled(): void
    {
        if (is_file($this->lockPath())) {
            abort(404);
        }
    }

    private function lockPath(): string
    {
        return storage_path(self::LOCK_RELATIVE_PATH);
    }

    private function tokenPath(): string
    {
        return base_path(self::TOKEN_RELATIVE_PATH);
    }

    /**
     * Valeurs proposées par défaut, reprises du template de production.
     */
    private function defaults(Request $request): array
    {
        $template = $this->templateValues();

        $url = (string) ($template['APP_URL'] ?? '');
        if ($url === '') {
            $url = ($request->isSecure() ? 'https://' : 'http://').$request->getHost();
        }

        return [
            'app_url' => $url,
            'db_host' => (string) ($template['DB_HOST'] ?? 'localhost'),
            'db_port' => (string) ($template['DB_PORT'] ?? '3306'),
            'db_database' => (string) ($template['DB_DATABASE'] ?? ''),
            'db_username' => (string) ($template['DB_USERNAME'] ?? ''),
            'db_password' => '',
            'admin_username' => (string) ($template['ADMIN_USERNAME'] ?? 'admin'),
            'admin_email' => (string) ($template['ADMIN_EMAIL'] ?? ''),
            'admin_password' => '',
        ];
    }

    /**
     * Le template sert uniquement à pré-remplir le formulaire : il ne contient
     * aucun secret, seulement les noms de domaine et de base.
     *
     * @return array<string, string>
     */
    private function templateValues(): array
    {
        $template = base_path(self::TEMPLATE_RELATIVE_PATH);

        if (! is_file($template)) {
            return [];
        }

        try {
            return Dotenv::createArrayBacked(base_path(), self::TEMPLATE_RELATIVE_PATH)->load();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function formValues(Request $request): array
    {
        $appUrl = trim((string) $request->input('app_url'));

        // L'utilisateur tape souvent le domaine sans schéma.
        if ($appUrl !== '' && ! preg_match('#^https?://#i', $appUrl)) {
            $appUrl = 'https://'.$appUrl;
        }

        return [
            'app_url' => rtrim($appUrl, '/'),
            'db_host' => trim((string) $request->input('db_host')),
            'db_port' => trim((string) $request->input('db_port')),
            'db_database' => trim((string) $request->input('db_database')),
            'db_username' => trim((string) $request->input('db_username')),
            'db_password' => (string) $request->input('db_password'),
            'admin_username' => trim((string) $request->input('admin_username')),
            'admin_email' => trim((string) $request->input('admin_email')),
            'admin_password' => (string) $request->input('admin_password'),
        ];
    }

    private function validator(array $values): \Illuminate\Validation\Validator
    {
        return Validator::make($values, [
            'app_url' => ['required', 'url', 'max:255'],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['present', 'nullable', 'string', 'max:255'],
            'admin_username' => ['required', 'string', 'min:3', 'max:50'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:255'],
        ], [
            'app_url.required' => "L'adresse du site est obligatoire (ex. https://iziwork.exemple.ci).",
            'app_url.url' => "L'adresse du site n'est pas une URL valide.",
            'db_host.required' => "L'hôte de la base est obligatoire.",
            'db_port.integer' => 'Le port doit être un nombre (3306 en général).',
            'db_database.required' => 'Le nom de la base est obligatoire.',
            'db_username.required' => "Le nom d'utilisateur MySQL est obligatoire.",
            'admin_username.min' => "Le nom d'utilisateur admin doit faire au moins 3 caractères.",
            'admin_email.required' => "L'email admin est obligatoire.",
            'admin_email.email' => "L'email admin n'est pas valide.",
            'admin_password.required' => 'Le mot de passe admin est obligatoire.',
            'admin_password.min' => 'Le mot de passe admin doit faire au moins 12 caractères.',
        ]);
    }

    /**
     * Applique les identifiants du formulaire à la config en mémoire : le
     * fichier .env n'existe pas encore (ou n'est pas relu) au moment du test.
     */
    private function connectToDatabase(array $values): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => $values['db_host'],
            'database.connections.mysql.port' => $values['db_port'],
            'database.connections.mysql.database' => $values['db_database'],
            'database.connections.mysql.username' => $values['db_username'],
            'database.connections.mysql.password' => $values['db_password'],
        ]);

        DB::purge('mysql');
        DB::connection('mysql')->getPdo();
    }

    private function writeEnvFile(array $values): void
    {
        $template = base_path(self::TEMPLATE_RELATIVE_PATH);

        if (! is_file($template)) {
            throw new RuntimeException(self::TEMPLATE_RELATIVE_PATH.' est absent de l\'installation.');
        }

        $env = (string) file_get_contents($template);

        $env = $this->setEnvValue($env, 'APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
        $env = $this->setEnvValue($env, 'APP_URL', $values['app_url']);
        $env = $this->setEnvValue($env, 'DB_CONNECTION', 'mysql');
        $env = $this->setEnvValue($env, 'DB_HOST', $values['db_host']);
        $env = $this->setEnvValue($env, 'DB_PORT', $values['db_port']);
        $env = $this->setEnvValue($env, 'DB_DATABASE', $values['db_database']);
        $env = $this->setEnvValue($env, 'DB_USERNAME', $values['db_username']);
        $env = $this->setEnvValue($env, 'DB_PASSWORD', $values['db_password']);
        $env = $this->setEnvValue($env, 'ADMIN_USERNAME', $values['admin_username']);
        $env = $this->setEnvValue($env, 'ADMIN_EMAIL', $values['admin_email']);
        $env = $this->setEnvValue($env, 'ADMIN_PASSWORD', $values['admin_password']);

        $target = base_path('.env');

        // Ne jamais écraser un .env existant sans sauvegarde.
        if (is_file($target)) {
            copy($target, base_path('.env.backup-'.date('Ymd-His')));
        }

        if (file_put_contents($target, $env) === false) {
            throw new RuntimeException('Écriture refusée dans '.base_path());
        }

        @chmod($target, 0600);
    }

    /**
     * Remplace la ligne KEY=... existante, ou l'ajoute en fin de fichier.
     */
    private function setEnvValue(string $content, string $key, string $value): string
    {
        $line = $key.'='.$this->encodeEnvValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content) === 1) {
            // preg_replace_callback : la valeur peut contenir "$" ou "\", qui
            // seraient interprétés comme références dans un remplacement brut.
            return (string) preg_replace_callback($pattern, fn (): string => $line, $content, 1);
        }

        return rtrim($content, "\n")."\n".$line."\n";
    }

    /**
     * Encode une valeur pour .env.
     *
     * Mesuré avec vlucas/phpdotenv 5.7 : les guillemets doubles (avec "\" et
     * """ échappés) sont le seul encodage qui relit correctement toutes les
     * valeurs, y compris celles contenant #, $, ", ' ou des espaces. Les
     * guillemets simples, eux, échouent dès que la valeur contient une
     * apostrophe ; les valeurs non quotées sont tronquées au premier #.
     */
    private function encodeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z0-9_.:/@+-]+$#', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Relit le .env écrit et vérifie que les identifiants n'ont pas été
     * altérés par le parseur (mot de passe tronqué au #, par exemple).
     */
    private function assertEnvRoundTrip(array $values): void
    {
        try {
            $parsed = Dotenv::createArrayBacked(base_path(), '.env')->load();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Le fichier .env écrit est illisible : '.$e->getMessage()
            );
        }

        $expected = [
            'DB_HOST' => $values['db_host'],
            'DB_DATABASE' => $values['db_database'],
            'DB_USERNAME' => $values['db_username'],
            'DB_PASSWORD' => $values['db_password'],
            'ADMIN_EMAIL' => $values['admin_email'],
        ];

        foreach ($expected as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new RuntimeException(
                    "Le fichier .env ne relit pas {$key} correctement. "
                    .'Le mot de passe contient probablement un caractère mal interprété : '
                    .'changez-le pour un mot de passe composé de lettres, chiffres et tirets '
                    .'(ex. Iziwork2026-Db9x), puis relancez cette page.'
                );
            }
        }
    }

    private function lockInstallation(array $values): void
    {
        file_put_contents($this->lockPath(), json_encode([
            'installed_at' => date('c'),
            'app_url' => $values['app_url'],
            'admin_email' => $values['admin_email'],
            'php' => PHP_VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<int, string>  $errors
     */
    private function renderForm(array $values, array $errors = []): View
    {
        return view('install.form', [
            'values' => $values,
            'installErrors' => $errors,
            'tokenFileExists' => is_file($this->tokenPath()),
            'tokenPath' => $this->tokenPath(),
            'lockPath' => $this->lockPath(),
        ]);
    }
}

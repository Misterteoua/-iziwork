<?php

declare(strict_types=1);

/**
 * Sonde de diagnostic — à téléverser dans le Document Root du sous-domaine.
 *
 * Usage :
 *   1. cPanel → Gestionnaire de fichiers → /home/efspcinphb/iziwork/public
 *   2. « Téléverser » → ce fichier (probe.php)
 *   3. ouvrir https://iziwork.efspc.inphb.ci/probe.php
 *
 * Elle ne fait que lire : aucun fichier n'est créé, modifié ou supprimé.
 * À supprimer du serveur une fois le déploiement terminé.
 *
 * Ce qu'elle répond : quel dossier est réellement servi, ce qu'il contient,
 * où se trouve l'application par rapport à lui, et si `.env` est exposé au web.
 */

header('Content-Type: text/plain; charset=utf-8');

const MAX_ENTRIES = 60;

function line(string $label, string $value): void
{
    printf("%-24s %s\n", $label, $value);
}

function listing(string $dir): void
{
    if (! is_dir($dir)) {
        echo "  (dossier inexistant)\n\n";

        return;
    }

    $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    sort($entries);

    if ($entries === []) {
        echo "  (dossier VIDE)\n\n";

        return;
    }

    $shown = 0;
    foreach ($entries as $entry) {
        $path = $dir.'/'.$entry;

        if ($shown++ >= MAX_ENTRIES) {
            printf("  … %d entrée(s) supplémentaire(s)\n", count($entries) - MAX_ENTRIES);
            break;
        }

        $kind = is_dir($path) ? 'dossier' : 'fichier';
        $size = is_dir($path) ? '' : sprintf(' %8d o', (int) @filesize($path));
        $warn = in_array($entry, ['.env', '.install-token', 'vendor', 'storage'], true) ? '   <-- à surveiller' : '';

        printf("  %-28s %s%s%s\n", $entry, $kind, $size, $warn);
    }

    echo "\n";
}

function looksLikeLaravel(string $dir): string
{
    $expected = ['artisan', 'app', 'bootstrap', 'config', 'public', 'routes', 'vendor', '.install-token'];
    $found = [];

    foreach ($expected as $item) {
        if (file_exists($dir.'/'.$item)) {
            $found[] = $item;
        }
    }

    return $found === [] ? 'non' : 'OUI ('.implode(', ', $found).')';
}

$docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

echo "=== Serveur ===\n";
line('Date', date('c'));
line('Logiciel', (string) ($_SERVER['SERVER_SOFTWARE'] ?? '?'));
line('PHP', PHP_VERSION.' — '.PHP_SAPI);
line('HTTPS', ! empty($_SERVER['HTTPS']) ? 'oui' : 'non');
line('Hôte', (string) ($_SERVER['HTTP_HOST'] ?? '?'));

echo "\n=== Chemins ===\n";
line('__DIR__ (sonde)', __DIR__);
line('DOCUMENT_ROOT', $docRoot !== '' ? $docRoot : '(absent)');
line('SCRIPT_FILENAME', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '(absent)'));
line('Chemin réel', (string) (realpath(__DIR__) ?: '?'));
line('Dossier servi = dossier de la sonde ?', $docRoot !== '' && realpath($docRoot) === realpath(__DIR__) ? 'oui' : 'NON');

echo "\n=== Contenu du dossier servi (DOCUMENT_ROOT) ===\n";
listing($docRoot !== '' ? $docRoot : __DIR__);

echo "=== Contenu de ".dirname(__DIR__)." (un niveau au-dessus) ===\n";
listing(dirname(__DIR__));

echo "=== Contenu de ".dirname(__DIR__, 2)." (deux niveaux au-dessus) ===\n";
listing(dirname(__DIR__, 2));

echo "=== Où est l'application ? ===\n";
foreach ([
    __DIR__,
    dirname(__DIR__),
    dirname(__DIR__, 2),
    dirname(__DIR__, 2).'/iziwork',
    dirname(__DIR__, 2).'/iziwork.efspc.inphb.ci/iziwork',
    '/home/efspcinphb/iziwork',
    '/home/efspcinphb/iziwork.efspc.inphb.ci',
] as $candidate) {
    printf("  %-58s laravel=%s\n", $candidate, looksLikeLaravel($candidate));
}

echo "\n=== .htaccess ===\n";
line('Dans le dossier servi', is_file(__DIR__.'/.htaccess') ? 'présent' : 'ABSENT');
line('Dans public/ de l\'app', is_file(dirname(__DIR__, 2).'/iziwork/public/.htaccess') ? 'présent' : 'absent ou app ailleurs');

echo "\n=== Extensions PHP ===\n";
foreach (['pdo_mysql', 'mysqlnd', 'mbstring', 'fileinfo', 'openssl', 'ctype', 'tokenizer', 'curl', 'dom', 'xml', 'gd', 'zip', 'intl'] as $ext) {
    line($ext, extension_loaded($ext) ? 'OK' : 'ABSENTE');
}

echo "\n=== Sécurité ===\n";
if ($docRoot !== '' && (is_file($docRoot.'/.env') || is_dir($docRoot.'/vendor') || is_dir($docRoot.'/app'))) {
    echo "  ALERTE : le Document Root contient la racine du projet (et non public/).\n"
        ."  .env et storage/ sont donc téléchargeables depuis le web : corrigez le Document Root.\n";
} else {
    echo "  OK : rien de sensible dans le Document Root.\n";
}

echo "\nSonde terminée. Supprimez probe.php du serveur après usage.\n";

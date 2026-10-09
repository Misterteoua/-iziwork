<?php

declare(strict_types=1);

/**
 * Contrôle qualité avant la construction d'une release.
 *
 * Une release ne doit pas partir avec un test rouge ou un fichier non conforme :
 * la suite de tests et Pint sont donc passés au crible ici. Rien n'est construit
 * ni déplacé — le script dit seulement si l'état du dépôt est publiable, et sort
 * en erreur sinon.
 *
 * Appelé par tools/release.php (avant toute construction) et par
 * tools/build-release.sh (pour l'appel direct, qui ne passe pas par release.php).
 *
 * Usage :
 *   php tools/check-quality.php
 *
 * Code de sortie :
 *   0  dépôt publiable ;
 *   1  tests rouges, style non conforme, ou Pint indisponible.
 */
$root = dirname(__DIR__);

chdir($root);

function step(string $message): void
{
    printf("\n\033[1;34m==>\033[0m %s\n", $message);
}

function note(string $message): void
{
    printf("    %s\n", $message);
}

/**
 * Lance une commande en relayant sa sortie telle quelle, et renvoie son code de
 * sortie. `passthru` évite de buffériser la sortie de la suite de tests, qui est
 * longue et doit rester lisible au moment où elle échoue.
 */
function run(array $command): int
{
    $status = 0;
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);

    return (int) $status;
}

/**
 * Le binaire de Pint, ou null s'il n'est pas installé.
 *
 * On vise le vrai script PHP du paquet, et non `vendor/bin/pint` : ce dernier est
 * un lanceur de shell sous Windows, qu'on ne peut pas confier à `php`.
 */
function pintBinary(string $root): ?string
{
    $binary = $root.'/vendor/laravel/pint/builds/pint';

    return is_file($binary) ? $binary : null;
}

// ------------------------------------------------------------------- Les tests

step('Suite de tests');
note('php artisan test');

$tests = run([PHP_BINARY, 'artisan', 'test']);

if ($tests !== 0) {
    note('la suite de tests a échoué');
}

// ---------------------------------------------------------------- La mise en forme

step('Mise en forme (Pint)');

$pint = pintBinary($root);

if ($pint === null) {
    note('Pint est introuvable : vendor/ est incomplet.');
    note('Lancez `composer install` (les outils de développement sont necessaires),');
    note('puis relancez : php tools/check-quality.php');
    exit(1);
}

note('pint --test');

$style = run([PHP_BINARY, $pint, '--test']);

if ($style !== 0) {
    note('des fichiers ne respectent pas le style du projet');
}

// --------------------------------------------------------------------- Verdict

if ($tests === 0 && $style === 0) {
    step('Contrôle qualité : OK');
    note('tests verts, mise en forme conforme');

    exit(0);
}

step('Contrôle qualité : ÉCHEC');
note("Rien n'a été construit.");

if ($tests !== 0) {
    note('· tests  : php artisan test');
}

if ($style !== 0) {
    note('· style  : php vendor/laravel/pint/builds/pint   (corrige sur place)');
}

note('Corrigez, puis relancez : php tools/check-quality.php');

exit(1);

<?php

declare(strict_types=1);

/**
 * Contrôle de l'archive de déploiement produite par tools/build-release.sh.
 *
 * Usage :
 *   php tools/verify-release.php [build/iziwork-release.zip]
 *
 * Vérifie, sans rien modifier :
 *   • l'intégrité du ZIP et son préfixe de dossier (`iziwork/`) ;
 *   • la présence des fichiers indispensables et l'absence de fichiers à ne
 *     jamais publier (.env, tests, .git, symlinks…) ;
 *   • que chaque fichier de l'archive est identique à sa version locale
 *     (détecte une archive construite avant la dernière modification) ;
 *   • la position des fichiers clés dans l'ordre de l'archive, pour repérer
 *     une extraction cPanel interrompue (les derniers fichiers manquent).
 *
 * Code de sortie : 0 si tout est bon, 1 sinon.
 */

const PREFIX = 'iziwork';

$expectedArchive = $argv[1] ?? 'build/iziwork-release.zip';
$root = dirname(__DIR__);

if (! is_file($expectedArchive)) {
    fwrite(STDERR, "Archive introuvable : {$expectedArchive}\nLancez d'abord : bash tools/build-release.sh\n");
    exit(1);
}

$required = [
    'artisan',
    'composer.json',
    'composer.lock',
    '.env.production.example',
    '.install-token',
    '.update-token',
    'app/Http/Controllers/InstallController.php',
    'bootstrap/app.php',
    'bootstrap/cache',
    'config/admin.php',
    'database/migrations',
    'public/index.php',
    'public/.htaccess',
    'public/favicon.ico',
    'resources/views/install/form.blade.php',
    'resources/views/install/success.blade.php',
    'routes/install.php',
    'routes/web.php',
    'storage/app/private',
    'storage/framework/views',
    'storage/logs',
    'vendor/autoload.php',
];

// Noms exacts interdits, préfixes de chemins interdits : `public/storage` est un
// lien vers les fichiers réellement téléversés, il ne doit jamais être publié.
$forbiddenNames = ['.env', 'storage/app/install.lock'];
$forbiddenPrefixes = ['.git/', 'tests/', 'build/', 'node_modules/', '.freebuff/', 'public/storage'];

// Fichiers de premier plan : leur position dans l'archive dit jusqu'où une
// extraction interrompue a pu aller.
$keyOrder = [
    'public/index.php',
    'vendor/autoload.php',
    'app/Http/Controllers/InstallController.php',
    'routes/install.php',
    'resources/views/install/form.blade.php',
];

$problems = [];
$notes = [];

$zip = new ZipArchive;

if ($zip->open($expectedArchive, ZipArchive::CHECKCONS) !== true) {
    fwrite(STDERR, "Archive illisible ou incohérente : {$expectedArchive}\n");
    exit(1);
}

$total = $zip->numFiles;
$uncompressed = 0;
$stored = [];
$position = [];
$lastEntries = [];

for ($i = 0; $i < $total; $i++) {
    $stat = $zip->statIndex($i);
    $name = str_replace('\\', '/', $stat['name']);
    $uncompressed += $stat['size'];
    $stored[$name] = $stat;
    $position[$name] = $i;
    $lastEntries[] = [$name, $stat['size']];
}

$isDir = static fn (string $name): bool => str_ends_with($name, '/');

echo "=== Archive ===\n";
printf("fichier   : %s\n", $expectedArchive);
printf("taille    : %.1f Mo compressés\n", filesize($expectedArchive) / 1048576);
printf("contenu   : %d entrées, %.1f Mo décompressés\n\n", $total, $uncompressed / 1048576);

// ---------------------------------------------------------- Préfixe et contenu
$badPrefix = [];
foreach ($stored as $name => $stat) {
    if (! str_starts_with($name, PREFIX.'/')) {
        $badPrefix[] = $name;
    }
}

echo "=== Préfixe ===\n";
if ($badPrefix === []) {
    printf("  OK : les %d entrées sont sous %s/ (extraction → un dossier iziwork/)\n\n", $total, PREFIX);
} else {
    printf("  ECHEC : %d entrée(s) hors %s/ (ex. %s)\n\n", count($badPrefix), PREFIX, $badPrefix[0]);
    $problems[] = 'entrées sans préfixe '.PREFIX.'/';
}

echo "=== Fichiers à ne pas publier ===\n";
$leaks = [];
foreach ($stored as $name => $stat) {
    $relative = substr($name, strlen(PREFIX) + 1);

    $forbidden = in_array($relative, $forbiddenNames, true)
        || str_starts_with($relative, '.env.backup');

    foreach ($forbiddenPrefixes as $path) {
        if ($relative === rtrim($path, '/') || str_starts_with($relative, $path)) {
            $forbidden = true;
        }
    }

    if ($forbidden) {
        $leaks[] = $name;
    }
}

if ($leaks === []) {
    echo "  OK : ni .env, ni tests/, ni .git/, ni public/storage\n\n";
} else {
    foreach (array_slice($leaks, 0, 15) as $leak) {
        echo "  FUITE : {$leak}\n";
    }

    if (count($leaks) > 15) {
        printf("  … %d entrée(s) de plus\n", count($leaks) - 15);
    }

    echo "\n";
    $problems[] = 'fichiers sensibles ou inutiles présents';
}

echo "=== Fichiers indispensables ===\n";
foreach ($required as $item) {
    $path = PREFIX.'/'.$item;
    $ok = isset($stored[$path]) || isset($stored[$path.'/']);

    printf("  %-58s %s\n", $item, $ok ? 'present' : 'ABSENT');

    if (! $ok) {
        $problems[] = "manquant : {$item}";
    }
}

echo "\n=== Cohérence avec le code local ===\n";
$checked = 0;
$stale = [];
$missingLocally = [];

foreach ($position as $name => $index) {
    if ($isDir($name)) {
        continue;
    }

    $relative = substr($name, strlen(PREFIX) + 1);

    if (str_starts_with($relative, 'vendor/')
        || str_starts_with($relative, 'bootstrap/cache/')
        || $relative === '.install-token') {
        continue; // vendor/ et le cache de découverte viennent de Composer, le jeton est régénéré
    }

    $local = $root.'/'.$relative;

    if (! is_file($local)) {
        $missingLocally[] = $relative;
        continue;
    }

    $checked++;

    /*
     | build-release.sh horodate APP_VERSION dans la copie embarquée de
     | .env.production.example : la ligne diffère donc volontairement du
     | dépôt. On compare en neutralisant cette ligne des deux côtés.
     */
    $normalize = static function (string $content): string {
        return implode("\n", array_filter(
            explode("\n", $content),
            static fn (string $line): bool => ! str_starts_with($line, 'APP_VERSION='),
        ));
    };

    $archiveContent = (string) $zip->getFromIndex($index);
    $localContent = (string) file_get_contents($local);

    if ($relative === '.env.production.example') {
        $staleMatch = hash('sha256', $normalize($localContent)) !== hash('sha256', $normalize($archiveContent));
    } else {
        $staleMatch = hash_file('sha256', $local) !== hash('sha256', $archiveContent);
    }

    if ($staleMatch) {
        $stale[] = $relative;
    }
}

printf("  %d fichiers comparés au dépôt\n", $checked);

if ($stale === []) {
    echo "  OK : contenu identique, l'archive n'est pas périmée\n\n";
} else {
    echo "  PERIMEE : ".count($stale)." fichier(s) diffèrent du dépôt :\n";
    foreach (array_slice($stale, 0, 10) as $file) {
        echo "    - {$file}\n";
    }
    echo "\n";
    $problems[] = 'archive construite avant la dernière modification du code';
}

if ($missingLocally !== []) {
    printf("  (info) %d fichier(s) de l'archive absents du dépôt (ex. %s)\n\n", count($missingLocally), $missingLocally[0]);
}

echo "=== Ordre de l'archive (risque d'extraction tronquée) ===\n";
foreach ($keyOrder as $item) {
    $path = PREFIX.'/'.$item;
    if (isset($position[$path]) && $position[$path] !== null) {
        printf("  %-58s entrée n°%d / %d\n", $item, $position[$path] + 1, $total);
    }
}

echo "\n  Dernières entrées (celles qui manquent si cPanel s'arrête en route) :\n";
foreach (array_slice($lastEntries, -5) as [$name, $size]) {
    printf("    %-58s %8d o\n", $name, $size);
}
echo "\n";

echo "=== Droits UNIX stockés dans l'archive ===\n";

$opsys = 0;
$flags = 0;
$fileAttr = 0;
$dirAttr = 0;

$zip->getExternalAttributesName(PREFIX.'/public/index.php', $opsys, $fileAttr, $flags);
$zip->getExternalAttributesName(PREFIX.'/app/', $opsys, $dirAttr, $flags);

$fileMode = ($fileAttr >> 16) & 07777;
$dirMode = ($dirAttr >> 16) & 07777;

printf("  public/index.php (fichier) : %04o\n", $fileMode);
printf("  app/ (dossier)             : %04o\n\n", $dirMode);

if ($fileMode === 0 && $dirMode === 0) {
    $notes[] = "aucune permission UNIX n'est stockée : à l'extraction, cPanel applique ses propres droits. Vérifiez ensuite que les dossiers sont en 755 et les fichiers en 644.";
} elseif (($dirMode & 0022) !== 0 || ($fileMode & 0022) !== 0) {
    $notes[] = 'droits trop ouverts stockés dans l\'archive : l\'extraction rendrait des fichiers modifiables par tous.';
}

$zip->close();

echo "=== Verdict ===\n";
if ($problems === []) {
    echo "  Archive conforme : prête à être uploadée et extraite.\n";
} else {
    foreach ($problems as $problem) {
        echo "  - {$problem}\n";
    }
}

if ($notes !== []) {
    echo "\nRemarques :\n";
    foreach ($notes as $note) {
        echo "  - {$note}\n";
    }
}

echo "\nAprès extraction, vérifiez côté serveur : /up doit répondre 200.\n";

exit($problems === [] ? 0 : 1);

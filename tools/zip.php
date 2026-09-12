<?php

/**
 * Construit une archive ZIP à partir d'un dossier, sans dépendre de la commande
 * `zip` (absente de Git Bash sous Windows et de certains hébergements).
 *
 * Usage :
 *   php tools/zip.php <dossier-source> <archive.zip>
 *
 * Les entrées de l'archive sont préfixées par le nom du dossier source, afin
 * que l'extraction depuis cPanel File Manager crée directement un sous-dossier
 * propre (ex. `iziwork/`) plutôt que de tout déverser dans le dossier courant.
 *
 * Deux exclusions de sécurité :
 *   • `public/storage` (lien vers `storage/app/public`) : il contient les
 *     fichiers réellement téléversés par les étudiants. Embarqué dans public/,
 *     il les rendrait téléchargeables par n'importe qui ;
 *   • tout autre lien (symlink Unix, jonction Windows), pour la même raison.
 *
 * Les droits UNIX (0755 dossiers, 0644 fichiers) sont écrits dans l'archive,
 * type de fichier compris (S_IFDIR / S_IFREG) : sans ces bits, libzip replie
 * les dossiers sur 0777, ce qui les rendrait modifiables par tout le monde.
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage : php tools/zip.php <dossier-source> <archive.zip>\n");
    exit(1);
}

$source = rtrim($argv[1], "/\\");
$target = $argv[2];

if (! is_dir($source)) {
    fwrite(STDERR, "Dossier source introuvable : {$source}\n");
    exit(1);
}

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "L'extension PHP `zip` est absente : impossible de créer l'archive.\n");
    exit(1);
}

$prefix = basename($source);
$zip = new ZipArchive();

if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Impossible d'ouvrir l'archive en écriture : {$target}\n");
    exit(1);
}

$directory = new RecursiveDirectoryIterator(
    $source,
    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::FOLLOW_SYMLINKS
);
$directory->setFlags(RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::CURRENT_AS_FILEINFO);

// SELF_FIRST : les dossiers sont ajoutés avant leur contenu (dossiers vides inclus).
$iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

// Chemins à ne jamais embarquer, exprimés relativement au dossier source.
// Le nom vient en complément de la détection de lien : sous Windows, PHP voit
// parfois une jonction comme un dossier ordinaire et en copierait le contenu.
$excluded = ['public/storage'];

$isExcluded = static function (string $relative) use ($excluded): bool {
    foreach ($excluded as $path) {
        if ($relative === $path || str_starts_with($relative, $path.'/')) {
            return true;
        }
    }

    return false;
};

/**
 * Inscrit les droits UNIX dans l'entrée, sans quoi cPanel applique ses propres
 * valeurs à l'extraction (fichiers parfois illisibles par le serveur web).
 * $mode doit inclure le type de fichier (0040000 dossier, 0100000 fichier).
 */
$applyMode = static function (ZipArchive $zip, string $name, int $mode, bool $isDir): void {
    if (! defined('ZipArchive::OPSYS_UNIX') || ! method_exists($zip, 'setExternalAttributesName')) {
        return;
    }

    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ($mode << 16) | ($isDir ? 0x10 : 0));
};

$files = 0;
$skipped = [];
$length = strlen($source);

foreach ($iterator as $item) {
    $path = $item->getPathname();

    // Ne jamais suivre un lien (une jonction ferait exploser la taille,
    // ou pire, publierait des données privées).
    if ($item->isLink() || is_link($path)) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($path, $length + 1));

    if ($isExcluded($relative)) {
        $skipped[] = $relative;
        continue;
    }

    $inside = $prefix.'/'.$relative;

    if ($item->isDir()) {
        $zip->addEmptyDir($inside);
        $applyMode($zip, $inside, 0040000 | 0755, true);
        continue;
    }

    $zip->addFile($path, $inside);
    $applyMode($zip, $inside, 0100000 | 0644, false);
    $files++;
}

$zip->close();

// libzip replie les droits des dossiers sur 0777 à l'écriture : les 0755
// demandés ci-dessus ne tiennent qu'en réécrivant les attributs archive fermée.
// Sans ce second passage, tous les dossiers arriveraient en 0777 (modifiables
// par tout le monde sur un hébergement mutualisé).
$directories = 0;
$reopened = new ZipArchive;

if ($reopened->open($target) === true) {
    for ($i = 0; $i < $reopened->numFiles; $i++) {
        $name = $reopened->statIndex($i)['name'];

        if (! str_ends_with($name, '/')) {
            continue;
        }

        $reopened->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ((0040000 | 0755) << 16) | 0x10);
        $directories++;
    }

    $reopened->close();
}

printf("Archive créée : %s (%d fichiers, %d dossiers, %.1f Mo)\n", $target, $files, $directories, filesize($target) / 1048576);

if ($skipped !== []) {
    printf("Exclus volontairement : %s\n", implode(', ', array_unique($skipped)));
}

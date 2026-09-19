<?php

declare(strict_types=1);

/**
 * Publie une release en une seule commande.
 *
 * Usage :
 *   php tools/release.php [options]
 *
 * Options :
 *   --url=https://exemple.tld  Domaine public (défaut : IZIWORK_URL, sinon le
 *                              sous-domaine de production du projet).
 *   --check                    Interroge la prod (/version) et dit si elle est
 *                              en retard par rapport à l'archive construite.
 *   --keep=N                   Archives horodatées conservées (défaut : 3, 0 = toutes).
 *   --no-build                 Ne reconstruit rien : contrôle et présente la
 *                              dernière archive déjà construite.
 *   --no-verify                Saute le contrôle d'intégrité (déconseillé).
 *   -h, --help                 Affiche cette aide.
 *
 * Le script enchaîne quatre étapes :
 *   1. construction  → tools/build-release.sh (code + vendor + jetons aléatoires)
 *   2. contrôle      → tools/verify-release.php (fuites, fichiers manquants,
 *                      archive périmée par rapport au dépôt)
 *   3. horodatage    → copie build/releases/iziwork-release-AAAA.MM.JJ-HHMM.zip
 *   4. affichage     → version, empreinte SHA-256, jeton /update à utiliser et
 *                      étapes de déploiement, plus un récapitulatif écrit dans
 *                      build/DERNIERE-RELEASE.txt
 *
 * Aucune donnée n'est modifiée en ligne : le script ne déploie pas, il prépare.
 */

const PREFIX = 'iziwork';
const DEFAULT_URL = 'https://iziwork.efspc.inphb.ci';

$root = dirname(__DIR__);
$build = $root.'/build';
$stage = $build.'/iziwork';
$zipPath = $build.'/iziwork-release.zip';
$releases = $build.'/releases';

// ---------------------------------------------------------------- Utilitaires

function step(string $message): void
{
    printf("\n\033[1;34m==>\033[0m %s\n", $message);
}

function note(string $message): void
{
    printf("    %s\n", $message);
}

function fail(string $message): never
{
    fwrite(STDERR, "\n\033[1;31m[x]\033[0m {$message}\n");
    exit(1);
}

function human(float $bytes): string
{
    return number_format($bytes / 1048576, 1, ',', ' ').' Mo';
}

/**
 * Localise un interpréteur shell capable de lire build-release.sh.
 *
 * On ne sonde pas en lançant la commande : sous Windows, la redirection de
 * sortie d'un test ferait échouer le probe. On cherche donc le binaire dans le
 * PATH, puis dans les emplacements habituels de Git for Windows (utile quand
 * release.php est lancé depuis PowerShell, où `bash` n'est pas toujours en
 * tête du PATH).
 */
function findShell(): ?string
{
    $candidates = array_values(array_filter([getenv('RELEASE_SHELL') ?: null, 'bash', 'sh']));
    $directories = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
    $extensions = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', '.bat', ''] : [''];

    $directories = array_merge($directories, [
        'C:\\Program Files\\Git\\bin',
        'C:\\Program Files\\Git\\usr\\bin',
        'C:\\Program Files (x86)\\Git\\bin',
        (string) getenv('LOCALAPPDATA').'\\Programs\\Git\\bin',
    ]);

    foreach ($candidates as $candidate) {
        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            return is_file($candidate) ? $candidate : null;
        }

        foreach ($directories as $directory) {
            foreach ($extensions as $extension) {
                $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$candidate.$extension;

                if (is_file($path)) {
                    return $path;
                }
            }
        }
    }

    return null;
}

/**
 * Lance une commande en relayant sa sortie telle quelle, et renvoie son code
 * de sortie. `passthru` évite de buffériser une sortie de plusieurs centaines
 * de lignes (le rapport de verify-release.php).
 */
function run(array $command): int
{
    $status = 0;
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);

    return (int) $status;
}

/** Lit une entrée de l'archive, sans la décompresser sur le disque. */
function entry(ZipArchive $zip, string $path): ?string
{
    $content = $zip->getFromName(PREFIX.'/'.$path);

    return $content === false ? null : $content;
}

function readVersion(?string $envExample): ?string
{
    if ($envExample === null) {
        return null;
    }

    return preg_match('/^APP_VERSION=(.+)$/m', $envExample, $matches) === 1
        ? trim($matches[1])
        : null;
}

// -------------------------------------------------------------------- Options

$options = [
    'url' => getenv('IZIWORK_URL') ?: DEFAULT_URL,
    'check' => false,
    'keep' => 3,
    'build' => true,
    'verify' => true,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo <<<'AIDE'
Publication d'une release Iziwork : construction, horodatage, contrôle et
jeton de mise à jour, en une seule commande.

Usage :
  php tools/release.php [options]

Options :
  --url=URL     Domaine public (défaut : $IZIWORK_URL, sinon le sous-domaine
                de production du projet).
  --check       Interroge la page /version en ligne et signale si la prod
                est en retard par rapport à cette archive.
  --keep=N      Nombre d'archives horodatées conservées (défaut : 3, 0 = toutes).
  --no-build    Ne reconstruit rien : contrôle et présente l'archive existante.
  --no-verify   Saute le contrôle d'intégrité (déconseillé).
  -h, --help    Affiche cette aide.

Exemples :
  php tools/release.php                 # construit et affiche le jeton à utiliser
  php tools/release.php --check         # en plus, compare avec la prod en ligne
  php tools/release.php --no-build      # relit la dernière archive construite

AIDE;
        exit(0);
    }

    if (str_starts_with($argument, '--url=')) {
        $options['url'] = rtrim(substr($argument, 6), '/');
    } elseif ($argument === '--check') {
        $options['check'] = true;
    } elseif (str_starts_with($argument, '--keep=')) {
        $options['keep'] = max(0, (int) substr($argument, 7));
    } elseif ($argument === '--no-build') {
        $options['build'] = false;
    } elseif ($argument === '--no-verify') {
        $options['verify'] = false;
    } else {
        fail("Option inconnue : {$argument} (voir --help)");
    }
}

if ($options['url'] === '' || ! preg_match('~^https?://~', $options['url'])) {
    fail("URL publique invalide : « {$options['url']} ». Exemple : --url=https://exemple.tld");
}

$baseUrl = $options['url'];

// ------------------------------------------------------------- Pré-requis PHP

// Les commandes relancent des scripts du dépôt par chemin relatif.
chdir($root);

if (! class_exists(ZipArchive::class)) {
    fail("L'extension PHP `zip` est absente : impossible de construire ou de lire l'archive.");
}

// ------------------------------------------------------- 1. Construction

if ($options['build']) {
    step('Construction de l\'archive');

    $shell = findShell();

    if ($shell === null) {
        fail('Aucun interpréteur shell trouvé (bash ou sh). Définissez RELEASE_SHELL=/chemin/vers/bash.');
    }

    note("shell : {$shell} · tools/build-release.sh");

    // RELEASE_QUIET : le script de construction n'affiche pas son propre mode
    // opératoire, c'est celui de release.php qui fait foi (avec l'horodatage).
    putenv('RELEASE_QUIET=1');
    $status = run([$shell, 'tools/build-release.sh']);

    if ($status !== 0) {
        fail("La construction a échoué (code {$status}). L'archive précédente reste intacte.");
    }
} else {
    step('Construction ignorée (--no-build)');
}

if (! is_file($zipPath)) {
    fail("Archive introuvable : {$zipPath}. Lancez sans --no-build.");
}

$zipSize = (float) filesize($zipPath);
$sha256 = (string) hash_file('sha256', $zipPath);

// --------------------------------------------- 2. Contrôle d'intégrité

if ($options['verify']) {
    step('Contrôle de l\'archive');

    $status = run([PHP_BINARY, 'tools/verify-release.php', $zipPath]);

    if ($status !== 0) {
        step('VERDICT : NE PAS DÉPLOYER');
        note('Le contrôle ci-dessus a relevé au moins un problème.');
        note('Corrigez la cause, puis relancez : php tools/release.php');
        exit(1);
    }
} else {
    step('Contrôle ignoré (--no-verify)');
}

// ------------------------------------------- 3. Lecture des métadonnées

$zip = new ZipArchive;

if ($zip->open($zipPath) !== true) {
    fail("Archive illisible : {$zipPath}");
}

// La vérité est dans l'archive : c'est elle qui arrivera sur le serveur.
$updateToken = entry($zip, '.update-token');
$installToken = entry($zip, '.install-token');
$version = readVersion(entry($zip, '.env.production.example'));

// Les fichiers de travail servent de repli (archive construite puis déplacée).
if ($updateToken === null && is_file($stage.'/.update-token')) {
    $updateToken = (string) file_get_contents($stage.'/.update-token');
}

if ($installToken === null && is_file($stage.'/.install-token')) {
    $installToken = (string) file_get_contents($stage.'/.install-token');
}

$zip->close();

$updateToken = trim((string) $updateToken);
$installToken = trim((string) $installToken);
$version = $version ?? 'dev';

if ($updateToken === '') {
    fail("Aucun jeton de mise à jour dans l'archive : elle a été construite avant la génération du jeton.");
}

// ------------------------------------------------- 4. Copie horodatée

step('Archivage horodaté');

if (! is_dir($releases) && ! mkdir($releases, 0755, true) && ! is_dir($releases)) {
    fail("Impossible de créer {$releases}");
}

// La version est de la forme AAAA.MM.JJ.HHMM : elle sert d'horodatage, elle
// est donc lisible dans le nom du fichier et comparable à celle de /version.
$stamp = preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $version) === 1
    ? $version
    : date('Y.m.d.Hi');

$datedName = substr($stamp, 0, 10).'-'.substr($stamp, 11);
$datedPath = $releases.'/iziwork-release-'.$datedName.'.zip';

if (! copy($zipPath, $datedPath)) {
    fail("Impossible d'écrire {$datedPath}");
}

note('copie : build/releases/'.basename($datedPath));

// Purge des archives les plus anciennes : le nom est horodaté, donc l'ordre
// alphabétique est l'ordre chronologique.
$history = glob($releases.'/iziwork-release-*.zip') ?: [];
rsort($history);

if ($options['keep'] > 0 && count($history) > $options['keep']) {
    foreach (array_slice($history, $options['keep']) as $old) {
        unlink($old);
        note('purge : '.basename($old));
    }
}

// --------------------------------------------------- 5. État de la prod

$prodVersion = null;

if ($options['check']) {
    step('Version en ligne');

    $context = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: iziwork-release\n"],
    ]);

    $body = @file_get_contents($baseUrl.'/version', false, $context);

    if ($body === false) {
        note("pas de réponse de {$baseUrl}/version (hors ligne ou protégé)");
    } else {
        $payload = json_decode($body, true);
        $prodVersion = is_array($payload) ? ($payload['version'] ?? null) : null;

        if ($prodVersion === null) {
            note("réponse inattendue de /version : ".trim(substr($body, 0, 120)));
        } elseif ($prodVersion === $version) {
            note("prod déjà en {$prodVersion} : cette archive n'apporte rien de nouveau");
        } else {
            note("prod en {$prodVersion} → à mettre à jour vers {$version}");
        }
    }
}

// ----------------------------------------------------------- Récapitulatif

$manifest = $build.'/DERNIERE-RELEASE.txt';
$updateUrl = $baseUrl.'/update?token='.$updateToken;

$report = implode("\n", [
    'Release    : '.$version,
    'Construite : '.date('d/m/Y H:i'),
    'Archive    : build/iziwork-release.zip ('.human($zipSize).')',
    'Copie      : build/releases/'.basename($datedPath),
    'SHA-256    : '.$sha256,
    'Jeton update : '.$updateToken,
    'Jeton install: '.$installToken,
    'URL update : '.$updateUrl,
    '',
]);

file_put_contents($manifest, $report);

$separator = str_repeat('=', 72);

echo "\n{$separator}\n";
printf(" RELEASE IZIWORK  ·  version %s\n", $version);
echo "{$separator}\n\n";

printf("  Archive à uploader : build/iziwork-release.zip  (%s)\n", human($zipSize));
printf("  Copie horodatée    : build/releases/%s\n", basename($datedPath));
printf("  SHA-256            : %s…\n", substr($sha256, 0, 16));
printf("  Récapitulatif      : build/DERNIERE-RELEASE.txt\n");

if ($prodVersion !== null && $prodVersion !== $version) {
    printf("  En ligne           : %s (à mettre à jour)\n", $prodVersion);
}

echo "\n  ┌─ JETON DE MISE À JOUR À UTILISER ".str_repeat('─', 31)."\n";
printf("  │  %s\n", $updateToken);
echo "  └".str_repeat('─', 65)."\n";

echo "\n  Étapes de déploiement :\n";
echo "    1. cPanel → Gestionnaire de fichiers → racine du compte → téléverser\n";
echo "       build/iziwork-release.zip\n";
echo "    2. clic droit sur le fichier → Extraire, par-dessus le dossier iziwork/\n";
echo "       (.env et storage/ ne sont pas dans l'archive : ils sont conservés)\n";
printf("    3. ouvrir : %s\n", $updateUrl);
printf("    4. vérifier : %s/up (200) puis %s/version\n", $baseUrl, $baseUrl);

echo "\n  À savoir :\n";
echo "    · ce jeton n'est valable que pour la prochaine mise à jour. Après\n";
echo "      succès il tourne et le nouveau s'affiche en fin de page ;\n";
echo "    · si le jeton est refusé (403), l'archive n'a pas encore été extraite\n";
echo "      sur le serveur, ou une mise à jour a déjà eu lieu entre-temps ;\n";
echo "    · jeton d'installation (première installation seulement) :\n";
printf("      %s\n", $installToken);
echo "\n";

#!/usr/bin/env bash
#
# Construit une archive prête à déployer sur cPanel, sans SSH ni Terminal.
#
# Usage :
#   bash tools/build-release.sh
#
# Produit build/iziwork-release.zip : code + dépendances de production (vendor)
# + un jeton d'installation aléatoire. Il ne reste plus qu'à uploader ce ZIP
# dans le Gestionnaire de fichiers, l'extraire, puis ouvrir /install.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/iziwork"
ZIP="$BUILD/iziwork-release.zip"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

command -v php >/dev/null 2>&1 || die "PHP est introuvable dans le PATH."

if [ -n "${COMPOSER_BIN:-}" ]; then
    COMPOSER="$COMPOSER_BIN"
elif command -v composer >/dev/null 2>&1; then
    COMPOSER="$(command -v composer)"
else
    die "Composer est introuvable. Installez-le ou passez COMPOSER_BIN=/chemin/vers/composer."
fi

cd "$ROOT"

# On ne supprime que l'espace de travail et l'archive stable : le dossier
# build/releases/ (archives horodatées par tools/release.php) est conservé.
log "Nettoyage de l'espace de travail"
rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

log "Copie des fichiers de l'application"
# Liste explicite : .env, vendor, tests, build/ et .git ne doivent jamais
# partir sur le serveur. `.env.production.example` sert de gabarit à
# l'assistant d'installation.
for item in app bootstrap config database lang public resources routes artisan \
            composer.json composer.lock .env.production.example; do
    [ -e "$item" ] || die "Élément attendu introuvable : $item"
    cp -r "$item" "$STAGE/"
done

log "Installation des dépendances de production (sans les paquets de dev)"
COMPOSER_MEMORY_LIMIT=-1 "$COMPOSER" install \
    --working-dir="$STAGE" \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

log "Préparation des dossiers d'exécution"
mkdir -p "$STAGE"/storage/framework/cache/data \
         "$STAGE"/storage/framework/sessions \
         "$STAGE"/storage/framework/views \
         "$STAGE"/storage/logs \
         "$STAGE"/storage/app/private \
         "$STAGE"/storage/app/public \
         "$STAGE"/bootstrap/cache

log "Génération du jeton d'installation"
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;' > "$STAGE/.install-token"

log "Génération du jeton de mise à jour"
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;' > "$STAGE/.update-token"

log "Habillage du numéro de version"
VERSION="$(date +%Y.%m.%d).$(date +%H%M)"
sed -i "s/^APP_VERSION=.*/APP_VERSION=$VERSION/" "$STAGE/.env.production.example" 2>/dev/null || \
  sed -i "5a\\APP_VERSION=$VERSION" "$STAGE/.env.production.example"

[ -f "$STAGE/vendor/autoload.php" ] || die "vendor/autoload.php est absent : l'installation a échoué."
[ -d "$STAGE/public" ] || die "public/ est absent de l'archive."

log "Création de l'archive"
php tools/zip.php "$STAGE" "$ZIP"

INSTALL_TOKEN="$(cat "$STAGE/.install-token")"
UPDATE_TOKEN="$(cat "$STAGE/.update-token")"

# RELEASE_QUIET=1 : tools/release.php enchaîne les étapes et affiche lui-même
# le mode opératoire complet ; ce bandeau ferait alors doublon.
if [ -z "${RELEASE_QUIET:-}" ]; then
cat <<EOF

Archive prête : $ZIP

--- Première installation ---
  1. cPanel → Gestionnaire de fichiers, à la racine du compte (hors public_html).
  2. Uploader $ZIP puis l'extraire : vous obtenez le dossier iziwork/.
  3. Créer la base MySQL dans cPanel, et noter le nom complet de la base
     et de l'utilisateur (avec le préfixe de compte).
  4. Pointer le Document Root du sous-domaine sur iziwork/public (chemin relatif !).
  5. Ouvrir https://votre-domaine/install et saisir les identifiants.
     Jeton d'installation : $INSTALL_TOKEN
     (il est aussi dans le fichier .install-token, supprimé après succès)

--- Mises à jour (après installation) ---
  1. Uploader $ZIP puis l'extraire par-dessus le dossier iziwork/ existant.
  2. Ouvrir https://votre-domaine/update?token=$UPDATE_TOKEN
     Les migrations sont lancées automatiquement, les caches vidés.
     Le jeton tourne : conservez le nouveau jeton affiché à la fin.

Le mot de passe du compte admin doit faire 12 caractères minimum.
EOF
else
    printf 'Archive prete : %s (%s)\n' "$ZIP" "$(du -h "$ZIP" | cut -f1)"
fi

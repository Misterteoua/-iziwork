#!/usr/bin/env bash
#
# Déploiement Iziwork (Laravel 12) — cPanel / CloudLinux
#
# Usage :  bash deploy.sh
#
# Idempotent : à relancer tel quel pour chaque mise à jour.
# Première installation : le script s'arrête après avoir créé .env depuis
# .env.production.example ; renseigne DB_PASSWORD + ADMIN_PASSWORD puis relance.
#
# Variables optionnelles :
#   APP_DIR=~/iziwork        dossier de l'application
#   PHP_BIN=/opt/alt/php82/usr/bin/php
#   COMPOSER_BIN=/usr/local/bin/composer
#
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/iziwork}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$APP_DIR" ] || die "Dossier introuvable : $APP_DIR (définis APP_DIR=...)"
cd "$APP_DIR"

# ---------------------------------------------------------------- 1. PHP 8.2+
find_php() {
    local c
    for c in "${PHP_BIN:-}" php php82 php8.2 \
             /opt/alt/php82/usr/bin/php /opt/alt/php83/usr/bin/php \
             /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php; do
        [ -n "$c" ] || continue
        command -v "$c" >/dev/null 2>&1 || continue
        if "$c" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' 2>/dev/null; then
            printf '%s' "$c"; return 0
        fi
    done
    return 1
}
PHP="$(find_php)" || die "PHP >= 8.2 introuvable. Passe le sous-domaine en 8.2 (MultiPHP Manager) puis relance."
log "PHP : $("$PHP" -v | head -1)"

# ---------------------------------------------------------------- 2. Composer
run_composer() {
    local bin="${COMPOSER_BIN:-}"
    if [ -z "$bin" ]; then
        if   [ -f /usr/local/bin/composer ]; then bin=/usr/local/bin/composer
        elif [ -f "$HOME/composer.phar"    ]; then bin="$HOME/composer.phar"
        elif [ -f ./composer.phar          ]; then bin=./composer.phar
        else bin="$(command -v composer 2>/dev/null || true)"
        fi
    fi
    if [ -n "$bin" ] && { [ -f "$bin" ] || command -v "$bin" >/dev/null 2>&1; }; then
        # Toujours exécuter Composer avec le PHP 8.2 détecté, jamais celui du PATH.
        COMPOSER_MEMORY_LIMIT=-1 "$PHP" "$bin" "$@"
    else
        die "Composer introuvable. Installe-le puis relance :
  php -r \"copy('https://getcomposer.org/installer','composer-setup.php');\"
  php composer-setup.php && rm composer-setup.php"
    fi
}

# ------------------------------------------------------------------ 3. Code
[ -d .git ] || die "Pas de dépôt git dans $APP_DIR. Clone d'abord le projet."
log "Récupération du code (git pull)"
git pull --ff-only

# ------------------------------------------------------------------- 4. .env
if [ ! -f .env ]; then
    cp .env.production.example .env
    warn "Fichier .env créé depuis .env.production.example."
    warn "Renseigne maintenant DB_PASSWORD, ADMIN_EMAIL et ADMIN_PASSWORD,"
    warn "puis relance :  bash deploy.sh"
    exit 1
fi

# --------------------------------------------------------- 5. Dépendances PHP
log "composer install"
run_composer install --no-dev --optimize-autoloader --no-interaction --quiet

# ------------------------------------------- 6. Clé d'application + dossiers
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/private storage/app/public bootstrap/cache
chmod -R 775 storage bootstrap/cache

"$PHP" artisan config:clear >/dev/null 2>&1 || true
if grep -qE '^APP_KEY=.+' .env; then
    log "APP_KEY déjà présente"
else
    log "Génération de APP_KEY"
    grep -qE '^APP_KEY=' .env || printf '\nAPP_KEY=\n' >> .env
    "$PHP" artisan key:generate --force
fi

# ------------------------------------------------- 7. Base de données + admin
log "Migrations"
"$PHP" artisan migrate --force

log "Compte administrateur (idempotent)"
"$PHP" artisan db:seed --force

# ------------------------------------------------------------ 8. Optimisation
log "Caches de production"
"$PHP" artisan config:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

# ------------------------------------------------------------ 9. Vérifications
log "Connexion base de données"
"$PHP" artisan tinker --execute="try { DB::connection()->getPdo(); echo '  DB OK'; } catch (Throwable \$e) { echo '  ECHEC: '.\$e->getMessage(); }" || true

echo
log "Déploiement terminé."
echo "   Vérifie ensuite : $(grep -oE '^APP_URL=.*' .env | cut -d= -f2-)/up"
echo "   Logs en cas de souci : tail -n 50 storage/logs/laravel.log"

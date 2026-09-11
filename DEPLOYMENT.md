# Déploiement sur cPanel — Iziwork

Cible : **https://iziwork.efspc.inphb.ci**
Compte cPanel : `efspcinphb`
Base : `efspcinphb_iziworkdb` — Utilisateur : `efspcinphb_iziworkuser`

---

## 1. PHP : passer le sous-domaine en 8.2 (sans toucher au reste)

Laravel 12 exige **PHP ≥ 8.2** (`composer.json`). Le 8.1 global ne suffit pas.

**Bonne nouvelle : cPanel permet de choisir la version de PHP par domaine.**
Changer le PHP du *sous-domaine* n'affecte **pas** les autres applis du compte.

1. cPanel → **MultiPHP Manager**
2. Coche **uniquement** `iziwork.efspc.inphb.ci`
3. Choisis **PHP 8.2** (ou 8.3) → **Apply**
4. Les autres domaines restent en 8.1 ✅

> Vérifie ensuite les extensions **pour ce sous-domaine** : c'est sur PHP 8.2
> que se joue la disponibilité de `zip`.

### Extensions requises (par sous-domaine)

| Extension | Utilité | État |
|---|---|---|
| `pdo_mysql` | base de données | ✅ |
| `mbstring`, `fileinfo`, `openssl`, `ctype`, `tokenizer`, `curl`, `dom` | framework / PDF | ✅ |
| `gd` | logo dans les PDF | ✅ |
| **`zip`** | téléchargement des dépôts en ZIP | ♻️ optionnel (repli auto) |

**À propos du « conflit » `zip` / `pdo_mysql` :** ces deux extensions sont en
réalité **indépendantes** et peuvent être actives en même temps. La plupart du
temps, `zip` n'est simplement pas présente dans la liste des extensions de la
version de PHP en cours. Donc :

- refais le test **une fois le sous-domaine passé en 8.2** ;
- si `zip` apparaît, coche-la **avec** `pdo_mysql` (c'est plus rapide) ;

> **Plus bloquant :** si `zip` reste indisponible, l'application bascule
> **automatiquement** sur le repli pur-PHP (bibliothèque `maennchen/zipstream-php`,
> installée par `composer install`). Les boutons **« Tout télécharger »** et
> **« Télécharger »** fonctionnent dans les deux cas.
>
> Pour **forcer** le repli pur-PHP même si `zip` est présent :
> `ZIP_STREAM_FALLBACK=true` dans `.env` (puis `php artisan config:cache`).

### PHP en ligne de commande (SSH)

Toutes les commandes de ce guide utilisent `php`. Vérifie d'abord la version :

```bash
php -v | head -1
command -v php
```

- **Déjà en 8.2+** (cas de `efspcinphb` : `PHP 8.2.33 (/opt/alt/php82/usr/bin/php)`) → rien à faire, `php` suffit partout dans ce guide.
- **Resté en 8.1** → trouve le binaire 8.2 réellement installé, puis crée un raccourci :

```bash
# repère les binaires disponibles
ls -1 /opt/alt/php*/usr/bin/php /opt/cpanel/ea-php*/root/usr/bin/php 2>/dev/null
echo "alias php82='/opt/alt/php82/usr/bin/php'" >> ~/.bashrc   # adapte le chemin si besoin
source ~/.bashrc
php -v
```

> Ne force pas un chemin par défaut : sur CloudLinux le binaire 8.2 est souvent sous
> `/opt/alt/php82/` (et non `/opt/cpanel/ea-php82/`, qui n'existe pas sur tous les serveurs).

---

## 2. Sous-domaine et Document Root

cPanel → **Domains → Create A New Domain** (ou **Subdomains**) :

- Domaine : `iziwork.efspc.inphb.ci`
- **Document Root** : `/home/efspcinphb/iziwork/public`

> ⚠️ Le docroot doit pointer vers le dossier **`public/`** de Laravel, jamais la
> racine du projet (qui contient `.env`).
>
> Si cPanel impose un docroot dans `public_html`, clone le projet dans
> `/home/efspcinphb/public_html/iziwork` et pointe le docroot vers
> `/home/efspcinphb/public_html/iziwork/public`.

---

## 3. Récupérer le code (SSH)

```bash
cd ~
git clone https://github.com/Misterteoua/-iziwork.git iziwork
cd iziwork
```

> Dépôt privé : génère un **token GitHub** et clone via
> `https://<token>@github.com/Misterteoua/-iziwork.git iziwork`.

---

## 4. Installer les dépendances

```bash
COMPOSER_MEMORY_LIMIT=-1 php /usr/local/bin/composer install --no-dev --optimize-autoloader
```

Si `composer` n'est pas trouvé, installe-le localement :

```bash
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
php composer-setup.php && rm composer-setup.php
COMPOSER_MEMORY_LIMIT=-1 php composer.phar install --no-dev --optimize-autoloader
```

> Pas de `npm install` / `npm run build` : l'interface utilise Tailwind via CDN.

---

## 5. Configurer `.env`

```bash
cp .env.production.example .env
nano .env
```

Le template contient déjà ton domaine et ta base ; il reste à renseigner :

```env
DB_PASSWORD=le_mot_de_passe_de_efspcinphb_iziworkuser

ADMIN_EMAIL=ton-email@efspc.inphb.ci
ADMIN_PASSWORD=un_mot_de_passe_fort
```

> ⚠️ **Mot de passe MySQL contenant `#` ou `$` → entoure-le de guillemets simples.**
> Dotenv tronque la valeur au premier `#` (tout ce qui suit passe en commentaire) et
> interpole `$`. Résultat : `mysql -u … -p …` fonctionne, mais Laravel renvoie
> `SQLSTATE[HY000] [1045] Access denied … (using password: YES)`.
>
> ```env
> DB_PASSWORD='mot#de#passe'
> ```
>
> Vérifie ensuite que la valeur complète est bien lue (attendu : la même longueur
> que ton mot de passe réel) :
>
> ```bash
> php artisan config:clear
> php artisan tinker --execute="echo strlen((string) config('database.connections.mysql.password')).PHP_EOL;"
> php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'DB OK'.PHP_EOL; } catch (Throwable \$e) { echo 'ECHEC: '.\$e->getMessage().PHP_EOL; }"
> ```
>
> Le plus simple reste un mot de passe **lettres + chiffres + tirets** (ex. `Iziwork2026-Db9x`) :
> aucun échappement nécessaire. L'utilisateur étant dédié à cette base, le changer
> n'impacte aucun autre site.

> ⚠️ **`ADMIN_PASSWORD` est obligatoire** : une valeur **vide** ne retombe pas sur un mot
> de passe par défaut (Laravel renvoie une chaîne vide, pas la valeur de secours). Le seeder
> refuse alors de créer le compte et affiche une erreur explicite — sans quoi tu te
> retrouverais avec un compte admin impossible à utiliser. Mets au moins 12 caractères.

Puis :

```bash
php artisan config:clear
php artisan key:generate --force
```

> `key:generate` remplace la ligne `APP_KEY=` **existante** dans `.env`. Si elle manque
> (fichier non copié, ligne supprimée, espace avant `=`, `export APP_KEY`, casse différente),
> il affiche `Unable to set application key. No APP_KEY variable was found in the .env file.`
> → vérifie avec `grep -n "^APP_KEY=" .env`, et au besoin ajoute la ligne :
> `printf 'APP_KEY=\n' >> .env` puis relance `php artisan key:generate --force`.
> Le `config:clear` évite qu'une config en cache avec un ancien `app.key` fausse la détection.

> Si la connexion MySQL échoue, bascule `DB_HOST` entre `localhost` et `127.0.0.1`.
> Le nom d'utilisateur doit être le **nom complet** `efspcinphb_iziworkuser`.

---

## 6. Permissions

```bash
chmod -R 775 storage bootstrap/cache
```

---

## 7. Base de données + compte admin

```bash
php artisan migrate --force
php artisan db:seed --force
```

Le seeder crée l'admin avec `ADMIN_EMAIL` / `ADMIN_USERNAME` / `ADMIN_PASSWORD`,
lu via `config('admin.*')` (`config/admin.php`). Ce passage par la config est volontaire :
quand `config:cache` est actif, Laravel ne charge plus `.env`, donc `env('ADMIN_PASSWORD')`
renverrait `null` et le seeder croirait — à tort — que le mot de passe est vide.
⚠️ Après modification des `ADMIN_*`, il faut donc `php artisan config:clear` puis
`php artisan config:cache`, sinon les valeurs ne sont pas relues.

- **Idempotent** : le relancer ne remplace jamais le mot de passe d'un admin existant
  (affiche `Compte administrateur déjà présent`).
- Si `ADMIN_PASSWORD` est vide **hors environnement local**, le seeder **échoue volontairement**
  avec le message `ADMIN_PASSWORD est vide : le compte administrateur n'a pas été créé.`
  → renseigne la valeur dans `.env`, puis relance `php artisan db:seed --force`.
- En local (`APP_ENV=local`), une valeur absente utilise le mot de passe `password` (documenté
  dans le README) et affiche un avertissement.

**Connecte-toi puis change le mot de passe.**

---

## 8. Optimiser pour la production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Après toute modif de `.env` : **`php artisan config:clear`** puis `php artisan config:cache`.
> Le `config:clear` est **obligatoire** si un `config:cache` a déjà été lancé : sans lui,
> `.env` n'a plus aucun effet et l'ancienne valeur (ex. mot de passe tronqué) reste active.

`php artisan storage:link` **n'est pas nécessaire** (fichiers stockés en privé).

---

## 9. HTTPS

cPanel → **SSL/TLS Status** → **Run AutoSSL** pour `iziwork.efspc.inphb.ci`.
Le middleware ajoute déjà HSTS en HTTPS.

---

## 10. Vérifications

| Test | Attendu |
|---|---|
| `https://iziwork.efspc.inphb.ci/login` | page de connexion + logo + favicon |
| `https://iziwork.efspc.inphb.ci/up` | `200` |
| Connexion admin | tableau de bord |
| Créer un formulaire → lien étudiant → soumettre → télécharger | OK (ZIP natif ou repli ZipStream) |
| Onglet navigateur | icône Iziwork |

Logs en cas de souci : `tail -n 50 storage/logs/laravel.log`

---

## 11. Mises à jour

```bash
cd ~/iziwork
php artisan down --secret="mon-token"
git pull
COMPOSER_MEMORY_LIMIT=-1 php /usr/local/bin/composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

---

## Dépannage

| Symptôme | Cause / solution |
|---|---|
| **500** | `storage/logs/laravel.log` ; permissions ; `APP_KEY` |
| `composer install` refuse à cause de PHP | sous-domaine pas en 8.2, ou `php` SSH resté en 8.1 → `php82` |
| **404/403** | Document Root pas sur `.../iziwork/public` |
| `Class "ZipArchive" not found` | normal si `zip` absent : le repli ZipStream prend le relais automatiquement |
| PDF sans logo | extension `gd` inactive |
| Fichiers non téléchargeables | permissions `storage/app/private` |
| Sessions / déconnexions | `SESSION_DRIVER=file`, `SESSION_SECURE_COOKIE=true` |
| `SQLSTATE[HY000] [1045] Access denied ... (using password: YES)` alors que `mysql -u UTILISATEUR -p BASE` fonctionne | un `#` (ou `$`) non échappé dans `DB_PASSWORD` : Dotenv tronque la valeur au `#`. Entoure-la de guillemets **simples** → `DB_PASSWORD='mot#de#passe'`, puis `php artisan config:clear` |
| `.env` sans effet | `php artisan config:clear` après une modification, puis `config:cache` |
| `db:seed` : *ADMIN_PASSWORD est vide* alors qu'il est bien renseigné | configuration en cache obsolète : `php artisan config:clear`, puis relance. Les valeurs `ADMIN_*` sont lues via `config('admin.*')` (`config/admin.php`), donc survivent à `config:cache` — mais uniquement avec la valeur figée au moment du cache |
| `key:generate` : *No APP_KEY variable was found in the .env file* | `.env` absent ou sans ligne `^APP_KEY=` (espace avant `=`, `export`, casse) → `config:clear`, `grep -n "^APP_KEY=" .env`, `printf 'APP_KEY=\n' >> .env` |
| `key:generate` : *Permission denied* / écriture impossible | `.env` non inscriptible → `chmod 644 .env` |
| Icône onglet = Laravel | cache navigateur : `Ctrl+F5` |

---

## Sécurité (checklist)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Docroot = `public/` (`.env` hors du web)
- [ ] Mot de passe admin changé après le 1er login
- [ ] HTTPS actif (AutoSSL)
- [ ] `.env` non commité
- [ ] Sauvegardes régulières : base MySQL + dossier `storage/`

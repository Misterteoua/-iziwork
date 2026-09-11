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
> `ZIP_STREAM_FALLBACK=true` dans `.env` (puis `php82 artisan config:cache`).

### PHP en ligne de commande (SSH)

Par défaut `php` en SSH peut être resté en 8.1. Vérifie :

```bash
php -v
```

Si ce n'est pas 8.2+, utilise le binaire explicite :

```bash
/opt/cpanel/ea-php82/root/usr/bin/php -v
```

Pour créer un raccourci pratique dans ta session SSH :

```bash
echo "alias php82='/opt/cpanel/ea-php82/root/usr/bin/php'" >> ~/.bashrc
source ~/.bashrc
php82 -v
```

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
COMPOSER_MEMORY_LIMIT=-1 php82 /usr/local/bin/composer install --no-dev --optimize-autoloader
```

Si `composer` n'est pas trouvé, installe-le localement :

```bash
php82 -r "copy('https://getcomposer.org/installer','composer-setup.php');"
php82 composer-setup.php && rm composer-setup.php
COMPOSER_MEMORY_LIMIT=-1 php82 composer.phar install --no-dev --optimize-autoloader
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

Puis :

```bash
php82 artisan key:generate --force
```

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
php82 artisan migrate --force
php82 artisan db:seed --force
```

Le seeder crée l'admin avec `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
**Connecte-toi puis change le mot de passe.**

---

## 8. Optimiser pour la production

```bash
php82 artisan config:cache
php82 artisan route:cache
php82 artisan view:cache
```

> Après toute modif de `.env` : `php82 artisan config:cache` (ou `config:clear`).

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
php82 artisan down --secret="mon-token"
git pull
COMPOSER_MEMORY_LIMIT=-1 php82 /usr/local/bin/composer install --no-dev --optimize-autoloader
php82 artisan migrate --force
php82 artisan config:cache && php82 artisan route:cache && php82 artisan view:cache
php82 artisan up
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
| `.env` sans effet | `php82 artisan config:cache` |
| Icône onglet = Laravel | cache navigateur : `Ctrl+F5` |

---

## Sécurité (checklist)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Docroot = `public/` (`.env` hors du web)
- [ ] Mot de passe admin changé après le 1er login
- [ ] HTTPS actif (AutoSSL)
- [ ] `.env` non commité
- [ ] Sauvegardes régulières : base MySQL + dossier `storage/`

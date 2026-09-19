# Déploiement sur cPanel — Iziwork

Cible : **https://iziwork.efspc.inphb.ci**
Compte cPanel : `efspcinphb`
Base : `efspcinphb_iziworkdb` — Utilisateur : `efspcinphb_iziworkuser`

---

## 0. Accès au serveur — SSH non disponible

Relevé du 11/09/2026 sur l'IP du serveur `190.92.154.151` :

| Port | État | Service |
|---|---|---|
| 22, 2222, 2022, 2200, 8022 | **fermé** | SSH |
| 21 | ouvert | FTP |
| 2083 / 2087 | ouvert | cPanel / WHM |
| 80 / 443 | ouvert | web |

**Conclusion : on ne peut pas déployer à distance depuis un poste de travail.**
La clé RSA 2048 générée dans cPanel (empreinte
`SHA256:P75idtFLN/OqVQQmZdS/St/TmWzIZWvTnEmQPX3fOeg`) ne suffit pas : il faudrait
aussi la clé **privée** côté client (cPanel → **SSH Access** → **Manage SSH Keys** →
**View/Download Key**) *et* que l'hébergeur ouvre le port 22.

Trois voies praticables, par ordre de simplicité :

1. **Voie B — aucune console** (retenue) : une archive embarque ses dépendances
   PHP et un assistant web joue les migrations. → voir juste en dessous.
2. **Terminal cPanel** (cPanel → **Avancé** → **Terminal**) : une console web qui
   s'exécute **sur le serveur** ; `deploy.sh` y fait tout. → section 12.
3. Demander au support d'**activer SSH** et d'autoriser l'IP du poste → sections 1 à 11.

---

## Voie B — déploiement sans console (retenue)

Aucune commande à taper sur le serveur : ni SSH, ni Terminal, ni Composer.
L'archive contient `vendor/`, et un assistant web exécute `migrate` puis
`db:seed` côté PHP.

### B.1 Construire l'archive (sur votre poste)

**Une seule commande**, toujours la même, pour la première installation comme
pour les mises à jour :

```bash
php tools/release.php
```

Elle enchaîne quatre étapes et se termine par un encadré où le **jeton `/update`
à utiliser** est affiché en clair :

1. **construction** de `build/iziwork-release.zip` (~12 Mo) : code, `vendor/`
   (paquets de production uniquement), gabarit `.env.production.example`, et
   deux jetons aléatoires (`.install-token`, `.update-token`) ;
2. **contrôle d'intégrité** (voir plus bas). Si un problème est relevé, le script
   s'arrête sur `NE PAS DÉPLOYER` et **ne touche pas** à l'archive précédente ;
3. **horodatage** : copie dans `build/releases/iziwork-release-AAAA.MM.JJ-HHMM.zip`.
   Les 3 dernières sont conservées (`--keep=N`, `0` = toutes) ;
4. **affichage** : version, empreinte SHA-256, jeton à utiliser, étapes de
   déploiement de l'installation **et** de la mise à jour. Le récapitulatif est
   aussi écrit dans `build/DERNIERE-RELEASE.txt`.

Options utiles : `--check` interroge la prod (`/version`) et dit si l'archive
apporte vraiment quelque chose, `--no-build` relit la dernière archive sans
reconstruire, `--url=https://exemple.tld` change de domaine.

> `build/` est ignoré par Git : archives, jetons et récapitulatif ne sont jamais
> committés.

Le contrôle d'intégrité est indispensable et inclus dans la commande ci-dessus.
Pour le relancer seul (par exemple sur une archive déplacée) :

```bash
php tools/verify-release.php [chemin/vers/iziwork-release.zip]
```

Il vérifie l'intégrité du ZIP, le préfixe `iziwork/`, la présence des fichiers
indispensables, les droits UNIX inscrits (dossiers 0755, fichiers 0644) et
surtout l'**absence de fichiers à ne jamais publier** : `.env`, `tests/`, `.git/`
mais aussi `public/storage`. Ce dernier point est un piège réel : `public/storage`
est un lien vers `storage/app/public`, donc vers les fichiers **réellement
téléversés** par les étudiants ; embarqué dans le web, il les rend
téléchargeables par n'importe qui. Il signale aussi une archive construite avant
la dernière modification du code (empreintes SHA-256 comparées au dépôt).

### B.2 Créer la base MySQL

cPanel → **Bases de données MySQL** : créez la base et l'utilisateur, puis
ajoutez l'utilisateur à la base avec **tous les privilèges**. Notez les noms
**complets**, préfixe de compte compris (ex. `efspcinphb_iziworkdb`).

### B.3 Uploader et extraire

cPanel → **Gestionnaire de fichiers**, à la racine du compte (surtout pas dans
`public_html`) :

1. **Upload** → `iziwork-release.zip`
2. clic droit sur le fichier → **Extract** : vous obtenez un dossier `iziwork/`

### B.4 Pointer le Document Root

cPanel → **Domaines** : `iziwork.efspc.inphb.ci` → **Gérer** → champ
*Racine du document* : saisissez un **chemin relatif au compte**, soit
`iziwork/public`.

> ⚠️ **Ne saisissez jamais `/home/efspcinphb/iziwork/public`** : cPanel préfixe
> lui-même le dossier du compte, ce qui enregistre
> `/home/efspcinphb/home/efspcinphb/iziwork/public` — un dossier vide. Le site
> affiche alors un « Index of / » vide. Signe qui ne trompe pas : la notification
> de succès de cPanel doit afficher le chemin **une seule fois**.

C'est ce qui garde `.env` et `storage/` **hors du web**.

### B.5 Lancer l'assistant

Ouvrez `https://iziwork.efspc.inphb.ci/install`.

| Champ | Valeur |
|---|---|
| Jeton d'installation | le contenu de `.install-token` (affiché en B.1) |
| URL publique | `https://iziwork.efspc.inphb.ci` |
| Base / hôte / utilisateur | noms complets créés en B.2 |
| Compte admin | email + mot de passe, **12 caractères minimum** |

L'assistant écrit `.env`, génère `APP_KEY`, joue les migrations, crée le compte
administrateur, puis **se verrouille** et supprime le jeton.

### B.6 Vérifier

| Test | Attendu |
|---|---|
| `https://…/up` | `200` |
| `https://…/login` | page de connexion |
| Connexion avec le compte créé | tableau de bord |

Si vous obtenez une **erreur 500** : les dossiers `storage/` et
`bootstrap/cache/` ne sont pas inscriptibles. Gestionnaire de fichiers →
clic droit → **Change Permissions** → `755` (voire `775`) sur `storage`
(récursivement) et sur `bootstrap/cache`.

### B.7 Mettre à jour l'application

```bash
php tools/release.php
```

Puis :

1. uploader le nouvel `iziwork-release.zip` et l'**extraire par-dessus**
   (`.env` et `storage/` ne sont pas dans l'archive : ils sont donc conservés) ;
2. ouvrir l'URL d'update **affichée en bas du script**, de la forme
   `https://votre-domaine/update?token=JETON_DE_MISE_A_JOUR`
   (le jeton est aussi dans `.update-token` et dans `build/DERNIERE-RELEASE.txt`).

L'assistant de mise à jour lance automatiquement les migrations et vide les
caches. **Plus besoin de supprimer `install.lock` ni de ressaisir les
identifiants MySQL.** Le jeton tourne après chaque mise à jour : conservez le
nouveau jeton affiché à la fin.

> **Important :** le jeton de mise à jour (`.update-token`) est **différent**
> du jeton d'installation (`.install-token`). Le premier sert aux mises à jour,
> le second à la première installation.

Le compte admin **n'est jamais modifié** — `db:seed` est idempotent et ne
touche pas à un mot de passe existant. Seules les nouvelles migrations sont
jouées.

> **Questions à réponse rédigée (mise à jour du 20/09).** Trois migrations :
> l'énumération `form_fields.field_type` accepte une valeur de plus
> (`textarea`, ajoutée **en fin de liste** : les questions existantes restent
> valides), `form_fields.expected_answer` est créée, et `quiz_answers` gagne
> `answer_text` tandis que `is_correct` et `points_awarded` deviennent
> nullables — c'est ce `null` qui veut dire « pas encore corrigée ». Sur MySQL,
> ces trois migrations se traduisent par un `MODIFY` d'énumération et des
> `ADD`/`MODIFY COLUMN` : aucune donnée existante n'est réécrite, et les copies
> déjà notées gardent leur note.

> **Mode d'accès (liste préparée / libre), sans migration.** Il est désormais
> enregistré dans les réglages de l'évaluation, et non déduit du nombre de
> participations : compter les participations faisait basculer une évaluation
> libre en « liste préparée » dès la première copie rendue, ce qui bloquait
> l'étudiant suivant du même poste, à qui l'on réclamait une référence jamais
> reçue. Importer une liste ou générer des références enregistre le mode liste ;
> une évaluation plus ancienne, sans ce réglage, est classée la première fois que
> ses réglages sont enregistrés depuis l'écran d'administration (et, d'ici là,
> l'ancien indice est resserré : une référence encore non commencée suffit à
> reconnaître une liste préparée).

> **Aucune dépendance à installer pour le module d'évaluations.** Il n'ajoute
> ni paquet Composer ni extension PHP à activer : les migrations créent les
tables `quiz_attempts` et `quiz_answers` et ajoutent des colonnes *avec valeur
> par défaut* à `forms` et `form_fields`. Les formulaires de dépôt existants
> gardent donc exactement leur comportement (`type = 'deposit'` par défaut),
> et aucune donnée n'est touchée. L'import des questions et de la liste des
> étudiants se fait en PHP natif (lecture directe des archives .xlsx et .docx) :
> les extensions `zip` et `simplexml` sont les seules nécessaires (voir le
tableau des extensions plus bas).

### B.7.1 Vérifier les limites PHP

Après la première installation ou une mise à jour, vérifiez que `upload_max_filesize`
est suffisant (≥ 8M) pour permettre aux étudiants d'uploader leurs fichiers :

cPanel → **MultiPHP Manager** → cocher le sous-domaine → **PHP Options** →
chercher `upload_max_filesize` → valeur recommandée : **8M** ou **16M**.

> **Piège courant :** sur certains hébergements, la valeur par défaut est
> `512` (octets, pas Mo !), ce qui rejette tout fichier. Le fichier
> `.user.ini` à la racine du projet tente de forcer la limite, mais certains
> hébergeurs l'ignorent — seule la manipulation dans MultiPHP est fiable.

### B.7.2 Réinitialiser le mot de passe admin

Le compte admin est identique en local et en production (même email, même mot
de passe). Pour (re)définir le mot de passe, jamais en clair dans l'historique
shell — la saisie est masquée, demandée deux fois, minimum 12 caractères :

```bash
php artisan admin:reset-password
# ou pour un compte précis s'il y en a plusieurs :
php artisan admin:reset-password --email=henri.teoua@inphb.ci
```

> Fonctionne tel quel sur les deux environnements (SQLite en local, MySQL en
> prod). Sur le serveur, exécutez-le en SSH ; sans SSH, passez par cPanel →
> **Terminal**. Le seeder ne modifie jamais un admin existant, donc aucun
> risque de conflit avec `db:seed`.

### B.7.3 Sauvegardes (manuelles, à la demande)

La sauvegarde est volontairement **manuelle** pour ne pas solliciter le
serveur inutilement. Deux commandes sont disponibles :

- `php artisan backup:run` — exporte la base (structure + données, SQL
  restaurable tel quel) **et** archive tous les dépôts étudiants dans
  `storage/app/private/backups/backup_<date>.{sql,zip}` ;
- `php artisan backup:clean --keep=8` — supprime les paires les plus
  anciennes au-delà de 8 sauvegardes.

**Quand sauvegarder** : avant chaque mise à jour (étape B.7), avant toute
manipulation risquée, et périodiquement (une fois par semaine suffit dans la
plupart des cas).

**Sur le serveur (cPanel → Terminal)** :

```bash
/opt/alt/php82/usr/bin/php /home/efspcinphb/iziwork/artisan backup:run
/opt/alt/php82/usr/bin/php /home/efspcinphb/iziwork/artisan backup:clean --keep=8
```

**Récupération** : les fichiers restent sur le serveur — un problème de
disque les perdrait avec l'application. Téléchargez-les régulièrement
(Gestionnaire de fichiers → dossier `backups`) et gardez-en une copie sur
votre poste. Pour restaurer : importer le `.sql` via phpMyAdmin et
réextraire le `.zip` dans `storage/app/private/`.

**En local**, même commande : `php artisan backup:run`.

> Si vous changez d'avis et préférez l'automatiser : il suffit d'ajouter un
> cron cPanel « chaque minute » exécutant `php artisan schedule:run`, puis
> de décommenter les lignes `Schedule::command('backup:run')...` dans
> `routes/console.php`. La procédure complète est dans l'historique git.

### B.8 Retirer l'assistant (facultatif)

Une fois en ligne, l'assistant est déjà inerte (verrou + jeton supprimé). Pour
le supprimer complètement du serveur, effacez :

- `app/Http/Controllers/InstallController.php`
- `routes/install.php`
- le dossier `resources/views/install/`
- le bloc `then:` de `bootstrap/app.php`

> À ne faire qu'après la dernière mise à jour : les étapes B.7 en ont besoin.

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
| `simplexml`, `libxml` | lecture des fichiers Excel et Word importés | ✅ (activée avec `dom`) |
| **`zip`** | téléchargement des dépôts en ZIP, **import .xlsx / .docx** | ♻️ obligatoire pour l'import (repli auto pour le ZIP de dépôts) |

> **Import et extension `zip` :** les fichiers Excel et Word sont des archives
> ZIP. Sans l'extension `zip`, le téléchargement des dépôts reste possible (repli
> automatique) mais **l'import de questions et de listes d'étudiants ne l'est
> pas** : l'application affiche alors un message explicite invitant à activer
> l'extension dans MultiPHP Manager, ou à importer un fichier `.csv`, qui ne
> demande rien de particulier.

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
>
> **Sans SSH** (voir section 0) : utilise **cPanel → Git™ Version Control** →
> *Create* avec l'URL `https://github.com/Misterteoua/-iziwork.git`, le dépôt
> cloné dans `/home/efspcinphb/iziwork`, puis *Update from Remote* pour chaque
> mise à jour. Le dépôt étant privé, renseigne le token GitHub dans l'URL
> (`https://<token>@github.com/...`) ou dans les identifiants proposés par cPanel.

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

Un script idempotent, [`deploy.sh`](deploy.sh), enchaîne **toutes** les étapes
(8.2 → `git pull` → `composer install` → `APP_KEY` → `migrate` → `db:seed` →
caches → vérification de la base) :

```bash
cd ~/iziwork
bash deploy.sh
```

Il est sûr à relancer autant de fois que nécessaire :

- si `.env` est absent, il est créé depuis `.env.production.example` puis le
  script **s'arrête** en demandant `DB_PASSWORD` et `ADMIN_PASSWORD` ;
- `APP_KEY` n'est générée que si elle est vide ;
- `db:seed` ne remplace jamais le mot de passe d'un admin existant.

Pour un correctif urgent, l'équivalent manuel reste :

```bash
cd ~/iziwork
php artisan down --secret="mon-token"
git pull
COMPOSER_MEMORY_LIMIT=-1 php /usr/local/bin/composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

---

## 12. Première installation depuis le Terminal cPanel

Accessible via cPanel → **Avancé** → **Terminal** (console web sur le serveur).

```bash
# 1. Récupérer le code (ou passer par Git™ Version Control, section 3)
cd ~
git clone https://<token>@github.com/Misterteoua/-iziwork.git iziwork
cd iziwork

# 2. Tout installer : le script s'arrête pour demander .env
bash deploy.sh

# 3. Renseigner DB_PASSWORD, ADMIN_EMAIL et ADMIN_PASSWORD
nano .env

# 4. Relancer : migrations, compte admin, caches
bash deploy.sh
```

> Le script détecte tout seul un binaire PHP ≥ 8.2 (`php`, `php82`,
> `/opt/alt/php82/usr/bin/php`…) et exécute Composer avec **ce** binaire, jamais
> avec un `php` resté en 8.1. En cas de besoin, force-le :
> `PHP_BIN=/opt/alt/php82/usr/bin/php bash deploy.sh`.

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
| `Connection refused` sur le port 22 / 2222 | SSH désactivé sur ce compte (voir section 0) → Terminal cPanel ou demande d'activation au support |
| `deploy.sh` : *Composer introuvable* | installe `composer.phar` dans `~/` (section 4), ou passe `COMPOSER_BIN=/chemin/vers/composer` |
| `/install` renvoie **404** alors que je viens d'extraire l'archive | `storage/app/install.lock` existe déjà (installation précédente) → supprimez-le pour relancer l'assistant (voie B.7) |
| `/install` affiche *Jeton de sécurité manquant* | `.install-token` est absent → recréez-le dans le Gestionnaire de fichiers avec une longue chaîne aléatoire |
| Assistant : *Le fichier .env ne relit pas DB_PASSWORD correctement* | mot de passe MySQL trop exotique → utilisez lettres, chiffres et tirets (ex. `Iziwork2026-Db9x`), puis relancez |
| Assistant : *Connexion à la base impossible* | base ou utilisateur mal nommés (il faut le **nom complet** préfixé), ou utilisateur non rattaché à la base avec tous les privilèges |
| `php tools/release.php` : *Aucun interpréteur shell trouvé* | définissez `RELEASE_SHELL=/chemin/vers/bash`, ou lancez `bash tools/build-release.sh` suivi de `php tools/verify-release.php` |
| `build-release.sh` : *L'extension PHP `zip` est absente* | activez `ext-zip` dans le `php.ini` de votre installation locale |
| **403 « Access to this resource on the server is denied! »** sur `/up`, `/install`, `/index.php`, alors que `/favicon.ico` répond 200 | Le vhost n'a **pas de handler PHP** : LiteSpeed refuse de servir un `.php` comme fichier statique. Le journal du domaine (cPanel → **Metrics → Errors**) affiche `MIME type [application/x-httpd-php] for suffix '.php' does not allow serving as static file`. → cPanel → **MultiPHP Manager** → cocher le sous-domaine → **PHP 8.2** → Apply (pour forcer la reconstruction du vhost : passer en 8.1, Apply, puis revenir en 8.2, Apply). Si le 403 persiste, ouvrir un ticket en citant cette ligne de journal : la configuration du vhost doit être reconstruite |
| Le site affiche un **« Index of / » vide** | Le Document Root pointe sur un dossier vide — voir B.4 (préfixe `/home/<compte>` doublé par cPanel) |
| Un fichier de `storage/app/public` est **téléchargeable en ligne** (`https://…/storage/…`) | L'archive a suivi le lien `public/storage`. Vérifier avec `php tools/verify-release.php`, **supprimer le dossier `public/storage` du serveur**, puis reconstruire l'archive |
| Doute sur l'état réel du serveur (docroot servi, fichiers présents, extensions) | Téléverser [`tools/probe.php`](tools/probe.php) dans le Document Root et ouvrir `/probe.php` : il affiche le `DOCUMENT_ROOT` réellement servi, l'emplacement de l'application, la présence de `.htaccess` et les extensions PHP. À supprimer après usage |

---

## Sécurité (checklist)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Docroot = `public/` (`.env` hors du web)
- [ ] `storage/app/install.lock` présent et `.install-token` supprimé (assistant verrouillé)
- [ ] Mot de passe admin changé après le 1er login
- [ ] HTTPS actif (AutoSSL)
- [ ] `.env` non commité
- [ ] Sauvegardes régulières : base MySQL + dossier `storage/`

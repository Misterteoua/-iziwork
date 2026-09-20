# Iziwork - Dépôts de travaux et évaluations en ligne

Application web pour la collecte des travaux rendus par les étudiants et pour
l'organisation d'évaluations notées en ligne (questionnaires chronométrés,
corrigés automatiquement).

## 🚀 Fonctionnalités

### Admin
- **Tableau de bord** : Vue d'ensemble des formulaires et soumissions
- **Gestion des formulaires** : Création, modification, activation/désactivation
- **Paramètres** : Dates d'ouverture/fermeture, nombre max de dépôts, anonymat
- **Gestion des soumissions** : Consultation, téléchargement individuel ou en lot (ZIP)
- **Sécurité** : Authentification par session, mot de passe haché

### Évaluations en ligne (questionnaires)
- **Création du questionnaire** : question par question, avec propositions et barème
- **Import des questions** : depuis un fichier Excel (.xlsx), Word (.docx), CSV ou texte — modèle téléchargeable, lignes refusées signalées avec leur numéro
- **Import de la liste des étudiants** : nom, email et filière, avec génération automatique d'une référence par étudiant et liste nominative à télécharger
- **Tirage aléatoire** : un sous-ensemble de questions et/ou des propositions mélangées, différentes pour chaque candidat — l'ordre est figé au démarrage de l'épreuve
- **Références alphanumériques** : 10 caractères générés pour la liste des étudiants ; la référence sert de numéro d'anonymat
- **Chrono tenu par le serveur** : la durée est fixée au démarrage de l'épreuve, un rechargement de page ne la remet pas à zéro
- **Navigation linéaire** : une seule question affichée, réponse définitive, aucun retour en arrière possible — et, quand le navigateur le permet, la question suivante **remplace la précédente sans recharger la page**
- **Correction automatique** : note calculée côté serveur, jamais envoyée avant la fin de l'épreuve
- **Questions à réponse rédigée** : l'étudiant tape un texte, l'enseignant attribue les points depuis une page de correction qui **enchaîne les copies** les unes après les autres ; la note reste **provisoire** tant qu'une réponse attend, puis devient définitive
- **Récapitulatif PDF** : note, référence et temps utilisé, téléchargeable avec la seule référence — la note définitive y apparaît après correction
- **Surveillance (proctoring)** : blocage du copier-coller et du clic droit, plein écran proposé puis accordé par un geste dédié, journal horodaté des **seules** sorties réelles (onglet masqué, plein écran quitté) — valider une réponse n'est jamais compté
- **Résultats côté admin** : liste des participations, export CSV, réinitialisation d'une participation

### Étudiant (dépôt de travaux)
- **Accès via lien sécurisé** : Pas besoin de compte
- **Formulaire intelligent** : Validation en temps réel
- **Téléchargement** : PDF, Word, PowerPoint, ZIP (max 5 Mo)
- **Page récapitulative** : Consultation et téléchargement du résumé
- **Anonymat** : Code anonyme pour les examens

## 📋 Prérequis

- PHP ≥ 8.0
- MySQL / MariaDB
- Composer
- Apache ou Nginx avec PHP-FPM

## 🛠️ Installation

### 1. Cloner ou télécharger le projet

```bash
cd /chemin/vers/vos/projets
# Si vous avez le projet压缩包, décompressez-le
# Ou clonez-le depuis votre dépôt
```

### 2. Installer les dépendances

```bash
cd iziwork
composer install
```

### 3. Configurer la base de données

#### Option A : Via MySQL/MariaDB

1. Créez la base de données :
```sql
CREATE DATABASE iziwork CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Importez le schéma :
```bash
mysql -u root -p iziwork < database/setup.sql
```

#### Option B : Via Laravel

1. Modifiez le fichier `.env` avec vos paramètres de connexion :
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=iziwork
DB_USERNAME=root
DB_PASSWORD=
```

2. Lancez les migrations :
```bash
php artisan migrate
```

3. Créez l'utilisateur admin :
```bash
php artisan db:seed
```

### 4. Configurer le stockage

```bash
php artisan storage:link
```

### 5. Générer la clé d'application

```bash
php artisan key:generate
```

### 6. Lancer le serveur

```bash
php artisan serve
```

L'application sera accessible sur : `http://localhost:8000`

## 🚀 Déploiement en production (cPanel)

Guide complet pas-à-pas (sous-domaine, MySQL, HTTPS, mises à jour) :
**[DEPLOYMENT.md](DEPLOYMENT.md)**. Un template d'environnement de production est
fourni dans [`.env.production.example`](.env.production.example).

### Sans SSH ni Terminal (voie retenue)

```bash
php tools/release.php   # construit, contrôle, horodate et affiche le jeton /update
```

La commande imprime à la fin le **jeton de mise à jour à utiliser** et l'URL
`/update?token=…` correspondante. Uploadez l'archive dans le Gestionnaire de
fichiers, extrayez-la, pointez le Document Root sur `iziwork/public`, puis ouvrez
`/install` : l'assistant écrit `.env`, génère `APP_KEY`, joue les migrations et
crée le compte administrateur. Aucune commande à taper sur le serveur. Détail
complet au début de [DEPLOYMENT.md](DEPLOYMENT.md).

## 🔐 Connexion Admin

- **URL** : `http://localhost:8000/login`
- **Email** : `admin@iziwork.com`
- **Mot de passe** : `password`

> ⚠️ Changez le mot de passe après la première connexion !
>
> Pour éviter de semer un compte par défaut en production, définissez
> `ADMIN_USERNAME`, `ADMIN_EMAIL` et `ADMIN_PASSWORD` avant `php artisan db:seed`.

### Connexion impossible ?

Si vous obtenez « Email ou mot de passe incorrect. » avec les identifiants
ci-dessus, c'est que le compte admin n'existe pas encore en base (les
migrations créent les tables, pas le compte). Lancez :

```bash
php artisan db:seed
```

Le seeder est idempotent : le relancer ne crée pas de doublon et ne
réinitialise pas un mot de passe déjà modifié.

## 📁 Structure du projet

```
iziwork/
├── app/
│   ├── Http/
│   │   ├── Controllers/     # Contrôleurs
│   │   └── Middleware/      # Middleware d'authentification
│   └── Models/              # Modèles Eloquent
├── database/
│   ├── migrations/          # Migrations de la base de données
│   └── seeders/             # Seeders pour les données initiales
├── public/                  # Point d'entrée web
├── resources/
│   └── views/               # Vues Blade
│       ├── admin/           # Vues administrateur
│       ├── auth/            # Vues d'authentification
│       ├── layouts/         # Layouts de base
│       └── student/         # Vues étudiant
├── routes/
│   └── web.php              # Routes de l'application
└── storage/                 # Stockage des fichiers uploadés
```

## 📝 Utilisation

### Créer un formulaire (Admin)

1. Connectez-vous à l'administration
2. Cliquez sur "Nouveau formulaire"
3. Remplissez les informations :
   - Titre et description
   - Dates d'ouverture/fermeture
   - Nombre max de dépôts
   - Option anonymat
4. Ajoutez les champs (nom, email, téléphone, filière, fichiers)
5. Enregistrez et activez le formulaire

### Partager le lien

1. Allez dans la page du formulaire
2. Cliquez sur "Copier le lien" — ou « Copier le lien court »
3. Partagez le lien avec les étudiants

### Liens courts

Chaque formulaire et chaque évaluation disposent d'un **lien court** de huit
caractères — `/l/Ab12Cd34` au lieu de `/s/{jeton}` ou `/q/{jeton}` :

- il se recopie dans un message ou s'écrit au tableau sans faute de frappe ;
- il mène exactement là où mène le lien long, qui continue de fonctionner ;
- il est **stable** : le même lien à chaque consultation, et il disparaît avec le
  formulaire qu'il désigne.

Les codes sont tirés au sort (57⁸ combinaisons, alphabet sans O/0 ni I/1/L) et
les codes inconnus sont comptés : trente essais infructueux par minute et par
adresse suffisent à faire taire un balayage, alors qu'une salle entière derrière
une même connexion n'est jamais freinée. Pour un domaine encore plus court
(`https://izi.work/l/Ab12Cd34`), renseignez `SHORT_LINK_DOMAIN` dans `.env`.

### Soumettre un devoir (Étudiant)

1. Ouvrez le lien reçu
2. Remplissez les champs
3. Téléchargez vos fichiers
4. Vérifiez et validez

### Consulter les soumissions (Admin)

1. Allez dans la page du formulaire
2. Consultez la liste des soumissions
3. Téléchargez les fichiers individuellement ou en lot

## ✍️ Organiser une évaluation en ligne

1. **Créer l'évaluation** (« Évaluations » → « Nouvelle évaluation ») : titre, durée,
   quota de participants, anonymat, affichage de la note, surveillance.
2. **Ajouter les questions** : énoncé, propositions, case à cocher pour la ou les
   bonnes réponses, barème. Une question à choix unique n'accepte qu'une bonne réponse.
3. **Préparer les références** (facultatif) : soit en important la liste des
   étudiants (nom, email, filière), soit en générant un nombre de références.
   Chaque référence fait 10 caractères, sans lettres ambiguës (ni O/0, ni I/1/L).
   Le bouton « Télécharger la liste (CSV) » donne les références à distribuer.
   Importer une liste ou générer des références **fige le mode d'accès** :
   l'évaluation demandera la référence au lieu du nom, définitivement — même
   après que toutes les copies ont été rendues. Sans référence préparée,
   l'évaluation reste en **mode libre** : l'étudiant saisit son nom et reçoit sa
   propre référence, autant de fois que de candidats qui se présentent.
4. **Ouvrir l'évaluation** puis partager le lien `/q/{jeton}` — ou son **lien
   court** (`/l/Ab12Cd34`), avec son bouton « Copier le lien court ».
5. **Suivre les résultats** (« Résultats ») : note, barème, temps passé, nombre de
   sorties enregistrées — avec leur nature (onglet masqué, plein écran quitté),
   parce qu'un total indistinct ne raconte pas ce qui s'est passé. Export CSV
   possible, réinitialisation d'une participation en cas d'incident.

La liste des évaluations se filtre par **titre ou consignes**, **période de
création**, **état** (ouvertes / fermées) et se trie par date ou par titre. Les
filtres sont dans l'URL : un lien vers « les évaluations ouvertes de ce mois »
se partage tel quel.

Côté étudiant : saisie de la référence, une question à la fois, temps affiché et
rappelé par le serveur, remise de la copie ou rendu automatique à l'expiration.
La note, la référence et le temps apparaissent immédiatement, avec un
récapitulatif PDF retéléchargeable à tout moment avec la référence seule.

### Salle informatique : plusieurs candidats sur le même poste

Le poste n'est jamais confisqué par la copie précédente. Une fois une copie
rendue, la page d'accès reste affichée, avec un bandeau qui rappelle la copie
rendue et mène à son résultat ; le candidat suivant remplit le formulaire et
obtient **sa propre copie**, avec sa propre référence. Le bouton « Ce n'est pas
ma copie — laisser la place à un autre étudiant », présent sur la page de
résultat, détache aussi le poste.

Ce qui reste verrouillé :

- une **épreuve en cours** ne peut pas être détachée — le candidat qui se trompe
  de poste ne fait pas perdre à un autre le fil de sa copie ;
- une **référence déjà servie** reste refusée : libérer le poste n'ouvre pas une
  porte dérobée ;
- en mode libre, où rien ne distingue deux candidats, le garde-fou est le
  **quota de participants** (`max_submissions`) : posez-le si l'évaluation est
  ouverte sur un parc partagé.

### Importer les questions

Téléchargez le **modèle Excel** depuis la page de l'évaluation : une ligne par
question, avec l'énoncé en première colonne, les propositions (A à F) ensuite,
puis les bonnes réponses (`B`, `A C` ou `2`) et le barème.

- Le **type se déduit** du nombre de bonnes réponses : une seule → choix unique,
  plusieurs → choix multiple.
- Les propositions laissées vides sont ignorées, et les bonnes réponses
  désignées par leur lettre restent correctes (la lettre désigne la colonne).
- Une **ligne refusée n'est jamais perdue en silence** : elle est listée avec son
  numéro et son motif, et les autres lignes sont bien importées.
- Word est accepté sous les deux formes habituelles : un tableau (une ligne par
  question) ou un paragraphe par question, propositions séparées par `|`.
- Les anciens formats `.xls` et `.doc` sont refusés avec un message qui dit quoi
  faire : « Enregistrer sous » en `.xlsx` ou `.docx`.

### Questions à réponse rédigée

Une question ouverte se crée comme les autres : dans le formulaire, choisissez
« **Réponse rédigée (question ouverte)** ». Il n'y a alors ni proposition ni
bonne réponse — aucune machine ne note un texte libre — mais un champ
« **Réponse attendue** », votre guide de correction, jamais montré à l'étudiant.

À l'import, c'est la colonne **`Type`** du modèle qui les déclare : `QCM` ou
`Ouvert`. Un fichier sans cette colonne s'importe exactement comme avant. Une
ligne déclarée `Ouvert` qui contient des propositions est **refusée avec son
numéro**, jamais convertie en silence.

Ce qui se passe ensuite, dans l'ordre :

1. L'étudiant tape sa réponse dans une zone de texte (5 000 caractères maximum).
2. À la remise, les QCM sont notés ; les réponses rédigées sont marquées
   **en attente** — `null`, et non `0`, ce qui est toute la différence.
3. Sa note est annoncée comme **provisoire**, avec le nombre de réponses à
   corriger ; le récapitulatif PDF porte la même mention.
4. Dans **Résultats**, la copie apparaît avec un bouton « **Corriger (n)** ». La
   page de correction montre l'énoncé, votre guide, le texte de l'étudiant et un
   champ de points (0 au barème, **notes partielles acceptées**).
5. Dès que plus rien n'attend, la note devient définitive — l'étudiant la voit
   en retéléchargeant son récapitulatif avec sa référence, sans se reconnecter.

### L'étudiant suit son résultat

À la fin de l'épreuve, la page de résultat affiche un **lien de suivi**
personnel (`/l/Ab12Cd34`), avec un bouton « Copier mon lien » et un **QR code** :
scanné au téléphone, il ramène l'étudiant sur son résultat à tout moment, sans
mot de passe et sans dépendre du navigateur utilisé. Le **récapitulatif PDF**
porte le même QR code et la même adresse — c'est le document qu'il garde.

Ce lien ne fait que rendre la copie consultable de n'importe où : il ne remplit
jamais la session du poste, donc un lien reçu par message ne peut pas prendre la
place d'une épreuve en cours sur un ordinateur partagé. L'enseignant peut
retrouver (et copier) le lien de chaque étudiant depuis **Résultats**, à côté de
sa copie, et le bouton ↻ en **régénère** un : l'ancien cesse aussitôt de
fonctionner.

Le QR code est calculé côté serveur, sans extension PHP ni bibliothèque : il
s'affiche même si le navigateur bloque le JavaScript, et il se retrouve dans le
PDF imprimé.

**Correction en série.** Le bandeau des résultats propose « **Corriger les
copies à corriger (n)** » : vous ouvrez la première, vous enregistrez, et
l'application vous emmène directement à la suivante — sans repasser par la
liste. L'en-tête rappelle où vous en êtes (« copie 2 sur 7 »), un lien « Passer
cette copie » laisse une réponse pour plus tard, et la dernière copie ramène aux
résultats. Une copie dont une réponse est restée **en attente** (champ laissé
vide) retourne dans la file sans jamais se reproposer d'elle-même : un
« suivant » ne peut pas tourner en rond sur la même copie. En série, le bouton
devient « Enregistrer et passer à la suivante ».

Sur une question ouverte **sans réponse** (temps écoulé), il n'y a rien à
corriger : la question vaut zéro par absence, comme un QCM non répondu, et la
copie n'est pas laissée en attente.

Deux exports complètent la page des résultats : l'export CSV des résultats
gagne une colonne « Réponses libres à corriger », et « **Réponses rédigées
(CSV)** » produit une ligne par réponse (question, texte, points, barème,
état de correction) — les noms restent absents d'une évaluation anonyme.

### Tirage aléatoire et mélange des propositions

Trois réglages, tous désactivés par défaut :

- **Questions posées à chaque candidat** : laissez vide pour poser tout le
  questionnaire, ou indiquez un nombre pour tirer un sous-ensemble au hasard.
- **Mélanger l'ordre des questions** : chaque candidat reçoit le même ensemble
  dans un ordre différent.
- **Mélanger les propositions** : l'ordre A, B, C, D change d'un candidat à
  l'autre.

Ce qui rend ces réglages utilisables en examen : l'ordre tiré est **figé au
moment où le candidat commence** et enregistré pour sa copie. Recharger la page,
fermer l'onglet ou revenir plus tard redonne exactement la même épreuve. Une
question ajoutée pendant l'épreuve ne s'ajoute pas à une copie en cours. Enfin,
la correction est indépendante du mélange : deux candidats ayant coché la même
proposition obtiennent la même note, quel que soit l'ordre reçu — le barème suit
les questions réellement posées, pas la banque entière.

### Ce que la surveillance ne peut pas faire

Un site web ne peut **pas** empêcher techniquement les captures d'écran, ni
fermer réellement les autres onglets : cela exige un navigateur d'examen dédié
(Safe Exam Browser) ou une application native. Ce qui est en place ici est une
dissuasion forte et un journal exploitable : copier-coller et clic droit bloqués,
plein écran proposé, chaque sortie réelle horodatée et comptée — et un chrono
que le candidat ne peut pas manipuler, puisqu'il est vérifié à chaque requête.

### Ce que la surveillance compte, et ce qu'elle ne compte pas

Le journal ne retient que ce qui est **réellement subi** : le passage à un autre
onglet ou à une autre application (`tab_hidden`), et la sortie du plein écran
(`fullscreen_exit`, signalée par l'événement du navigateur lui-même). Une même
sortie signalée deux fois par le navigateur n'est écrite qu'**une seule fois**
— le dédoublonnage de deux secondes existe des deux côtés, dans la page et dans
le serveur.

Ce qui n'est **pas** compté, volontairement : valider une réponse, les transitions
de plein écran, les dialogues du navigateur, et la sortie du plein écran demandée
par le bouton « Quitter le plein écran » (c'est un geste de l'application, pas une
sortie subie). Le plein écran est **accordé par un geste dédié** — la case cochée
au démarrage, puis le bouton de la page d'épreuve — et jamais au hasard d'un clic
de réponse, comme c'était le cas avant.

Enfin, quitter la page n'est **plus bloqué** pendant toute l'épreuve : le
avertissement du navigateur ne subsiste que dans le seul cas où une sortie fait
vraiment perdre quelque chose — une question rédigée contenant du texte **tapé et
non validé**. Auparavant, ce garde-fou s'armait à chaque validation et le
navigateur abandonnait la navigation tant que le candidat n'avait pas confirmé un
dialogue que le plein écran rendait presque invisible.

## 🔒 Sécurité

- Authentification par session PHP
- Mots de passe hachés avec bcrypt
- Validation stricte des fichiers (type MIME, taille)
- Protection CSRF
- Validation côté client et serveur
- Liens sécurisés avec jeton unique

## 🛠️ Technologies

- **Backend** : PHP 8.0+ / Laravel 12
- **Base de données** : MySQL / MariaDB
- **Frontend** : HTML5 / Tailwind CSS / JavaScript
- **PDF** : Dompdf
- **Archives** : ZipArchive (PHP natif)

## 📄 Licence

Projet privé - Tous droits réservés

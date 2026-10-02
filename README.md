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
   quota de participants, anonymat, affichage de la note, surveillance, et
   l'anti-doublon par appareil (voir « Une seule copie par étudiant »).
2. **Ajouter les questions** : énoncé, propositions, case à cocher pour la ou les
   bonnes réponses, barème. Une question à choix unique n'accepte qu'une bonne réponse.
3. **Préparer les références** (facultatif) : soit en important la liste des
   étudiants (nom, email, filière), soit en générant un nombre de références.
   Chaque référence fait 10 caractères, sans lettres ambiguës (ni O/0, ni I/1/L).
   Le bouton « Télécharger la liste (CSV) » donne les références à distribuer.
   Nom, adresse et filière sont limités à **255 caractères** — la taille des
   colonnes : une cellule plus longue fait refuser **cette ligne**, avec son
   numéro, jamais le fichier entier.
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
   possible, réinitialisation d'une participation en cas d'incident, ou
   **suppression** d'une copie (voir « Réinitialiser ou supprimer une copie »).

La liste des évaluations se filtre par **titre ou consignes**, **période de
création**, **état** (ouvertes / fermées) et se trie par date ou par titre. Les
filtres sont dans l'URL : un lien vers « les évaluations ouvertes de ce mois »
se partage tel quel.

Côté étudiant : saisie de la référence, une question à la fois, temps affiché et
rappelé par le serveur, remise de la copie ou rendu automatique à l'expiration.
La note, la référence et le temps apparaissent immédiatement, avec un
récapitulatif PDF retéléchargeable à tout moment avec la référence seule. Le
**détail de la correction**, lui, attend sa publication (voir « Quand les
corrections sont publiées »).

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
- en mode libre, le candidat suivant est reconnu à son **adresse email** : une
  adresse ne remet qu'une copie par évaluation (voir ci-dessous).

### Une seule copie par étudiant

Trois barrières, de la plus ferme à la plus souple — et **aucune** ne dépend du
navigateur du candidat :

1. **La référence** (évaluations en mode liste, anonymes comprises). Une
   référence ouvre une copie, une seule : une fois la copie rendue, la ressaisir
   mène à son résultat, jamais à une seconde copie. C'est la clé d'identité de
   l'anonymat, et elle ne se partage pas.
2. **L'adresse email** (dès qu'une adresse existe : saisie par l'étudiant en mode
   libre, ou fournie par la liste importée). Une adresse = une copie, pour toute
   l'évaluation. Le contrôle porte sur les copies **engagées** (en cours, rendues,
   temps écoulé) : reprendre sa propre épreuve en cours reste possible, et une
   ligne de liste non commencée n'est pas une soumission. Effet utile : une même
   adresse présente deux fois dans une liste importée ne produit qu'une copie.
3. **L'appareil** (réglage « Une seule copie par appareil », désactivé par
   défaut). Un identifiant opaque, gardé dans un cookie **chiffré** d'un an, est
   recopié sur la copie au démarrage ; s'il a déjà servi pour une copie **rendue**
   de cette évaluation, une nouvelle copie est refusée. C'est le seul signal qui
   reste quand l'évaluation est anonyme *et* sans liste de références.

> **Salle informatique : laissez ce réglage décoché.** Les postes sont partagés :
> l'activer reviendrait à interdire au candidat suivant de commencer. Réservez-le
> aux épreuves passées à distance.

L'**adresse IP** n'est jamais un motif de refus : une salle entière sort derrière
une seule adresse. Elle est enregistrée sur chaque copie, exportée en dernière
colonne du CSV des résultats, et la page « Résultats » signale discrètement les
copies qui partagent la même adresse (« n copies depuis la même adresse IP ») —
un indice pour l'enseignant, jamais une sanction.

### Réinitialiser ou supprimer une copie

Deux gestes différents, dans la colonne « Actions » de la page des résultats :

- **Réinitialiser** garde la participation et rend la place : les réponses
  partent, la référence reste, l'étudiant peut repasser. C'est le geste des
  incidents (coupure, poste qui plante).
- **Supprimer** efface définitivement la copie : réponses, notes, commentaires,
  corrections des correcteurs et lien de suivi partent avec elle. En mode liste,
  sa **référence disparaît aussi**, et le candidat ne peut plus commencer. À
  réserver aux copies parasites ou aux essais de l'enseignant ; la confirmation
  le dit noir sur blanc.

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
- Un **énoncé long** est accepté : jusqu'à **2 000 caractères**, ce qu'un cas
  pratique atteint couramment (1 200 à 1 500 dans une épreuve réelle). Au-delà,
  la ligne est refusée avec son numéro, comme les autres.
- Les **retours à la ligne** d'une cellule (Excel `Alt+Entrée`, paragraphe Word)
  sont conservés : un énoncé écrit en plusieurs paragraphes arrive en plusieurs
  paragraphes. Seuls les espaces de mise en page sont normalisés.
- Un **cas pratique** qui contient plusieurs « Question 1 … Question 2 … » sur
  une seule ligne est **découpé en autant de questions**. Le contexte écrit avant
  la première question est recopié en tête de chacune, et le numéro d'origine est
  retiré du texte : chaque question se lit seule, et c'est le rang dans
  l'évaluation qui numérote. Le découpage ne se déclenche que sur des repères
  certains — au moins deux numéros qui se suivent, en début de phrase. Une phrase
  qui cite « la Question 1 du sujet précédent » n'est pas touchée, et une question
  à propositions garde ses propositions telles quelles.

**Rien n'est enregistré tant que l'enseignant n'a pas confirmé.** Le fichier est
analysé, puis montré : la page d'**aperçu** affiche chaque question telle qu'elle
sera créée (énoncés mis en forme, propositions, barème), les énoncés découpés et
les lignes refusées. C'est le bouton « Confirmer l'import » qui écrit. Le même
écran montre donc, avant de peser sur l'épreuve, qu'un cas pratique devient trois
questions — et non l'inverse.

L'aperçu ne vit pas seulement à l'écran : il est **enregistré côté serveur**. Un
enseignant peut fermer son navigateur, se déconnecter, ou reprendre le travail
depuis un autre appareil — la page de l'évaluation annonce l'import en attente, et
un lien le rouvre tel qu'il l'avait laissé, sans retéléverser le fichier. Un
nouvel import du même enseignant sur la même évaluation **remplace** le précédent
(un seul aperçu en attente à la fois), et un aperçu jamais confirmé est effacé au
bout d'**une semaine**. Rien de tout cela n'écrit de question : seul « Confirmer
l'import » enregistre.

Et ce que le découpage a proposé, l'enseignant le **corrige sur place** :

- **Retirer cette question** écarte une question du lot (une ligne mal comprise
  qui ne mérite pas d'être notée) ;
- **Fusionner avec la précédente** recoud deux morceaux d'un même énoncé découpé —
  le contexte recopié n'est pas répété à la jonction, et les repères se
  renumérotent ;
- **Scinder à la coupure** sépare une question en deux : on insère une ligne ne
  contenant que trois tirets (`---`, ou `***`, ou `___`) à l'endroit voulu, et la
  coupure disparaît du texte. La séparation joue aussi sur une question qu'aucun
  « Question N » ne distinguait, et les deux morceaux restent recousables ;
- **Modifier l'énoncé** déplace la limite : on coupe la fin d'une question et on
  la colle au début de la suivante.

Tout cela se fait dans un seul formulaire : chaque clic renvoie l'état complet des
énoncés, donc aucune retouche n'est perdue par l'action voisine. Aucune de ces
actions n'écrit quoi que ce soit — seul « Confirmer l'import » enregistre, et une
question retouchée qui ne passerait plus le contrôle revient à l'écran avec son
motif, sans rien inscrire.

### Mise en forme des textes longs

Un texte n'est jamais affiché tel qu'il a été stocké : il est **mis en forme à
l'affichage**, partout où il apparaît — page des questions, carte du candidat,
écran de correction, bulletin PDF. Cela vaut pour les textes longs d'une
évaluation :

- l'**énoncé** de la question,
- le **guide de correction** (la « réponse attendue » : c'est le correcteur qui
  le lit, jamais l'étudiant), à l'écran de correction et sur la page des
  questions,
- la **copie rendue** par l'étudiant, à la correction, sur son bulletin et dans
  le récapitulatif PDF,
- l'**appréciation** du correcteur, à l'écran de correction (le commentaire
  retenu après une reprise par l'administration, et le commentaire précédent au
  journal de la note) comme sur le bulletin de l'étudiant et dans son
  récapitulatif PDF.

Partout, les paragraphes se séparent, les retours à la ligne se voient, et les
énumérations deviennent de vraies listes, même lorsque l'import les avait collées
sur une seule ligne :

- `a) … b) … c) …` → liste `a. b. c.` (une énumération qui commence à `c) `
  reste `c. d.` : l'énoncé peut y renvoyer) ;
- `1. … 2. …` → liste numérotée ;
- `- … ; - …` (tiret, `•`, `*`) → liste à puces.

Les repères restent volontairement prudents, parce qu'une liste inventée déforme
un énoncé : il faut **au moins deux éléments qui se suivent**, et `2.5 %`, `N°1`,
`(QSE)` ou `etc.` ne sont pas des marqueurs. Un énoncé sans énumération s'affiche
exactement comme avant.

Le texte enregistré n'est **jamais modifié** : la mise en forme se contente
d'échapper et d'assembler, elle ne réécrit rien. Une présentation maladroite reste
donc cosmétique, et corriger l'énoncé reste la seule façon de changer son texte.

Dans le formulaire d'une question, un **aperçu** apparaît sous le champ d'énoncé
pendant la saisie : il montre la mise en forme telle que la verront le candidat et
le correcteur. L'aperçu ne réimplémente pas les règles dans le navigateur — il
demande au serveur le rendu de ce qui est en train d'être tapé, le seul endroit où
la mise en forme existe. Il ne juge rien non plus : un énoncé à moitié écrit
s'affiche, et le refus reste au moment de l'enregistrement.

Ceci vaut aussi pour la copie d'un candidat, qui reste du texte : ce qu'il a écrit
décide de sa mise en forme, mais rien de ce qu'il écrit ne peut injecter de balise
dans la page — ni dans celle du correcteur, ni dans son bulletin.

Une appréciation écrite en plusieurs lignes, ou avec ses propres énumérations,
s'affiche donc comme l'énoncé qu'elle commente — pour le correcteur pendant la
correction comme pour l'étudiant qui la découvre une fois sa copie entièrement
corrigée. Le texte stocké, lui, reste exactement ce que le correcteur a tapé.
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
   champ de points (0 au barème, **notes partielles acceptées**), suivi d'un
   **commentaire pour l'étudiant** — une note sans phrase est vécue comme
   arbitraire, et se défend mal devant une contestation. Chaque note enregistre
   qui l'a posée (« corrigé par Awa (correcteur) », « par admin »), visible sur la
   copie et dans les exports.
5. Dès que plus rien n'attend, la note devient définitive — l'étudiant la voit
   en retéléchargeant son récapitulatif avec sa référence, sans se reconnecter.
   Le **commentaire** apparaît à ce moment-là seulement, sur la page de résultat
   comme dans le PDF : sur une note provisoire, il serait lu comme définitif. Un
   champ laissé vide ne produit aucun cadre vide, et un commentaire écrit sans
   note est conservé (la réponse, elle, reste en attente).

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

### Quand les corrections sont publiées

Le détail d'une copie — bonnes réponses, barème par question, réponse rédigée du
candidat, appréciations des correcteurs — n'est **jamais montré tant que d'autres
candidats peuvent encore composer**. Trois leviers le publient, et **la première
échéance atteinte gagne** :

1. la **date de publication** choisie pour l'évaluation (« Publication des
   corrections », facultative). On publie à l'heure dite, sans attendre la
   fermeture : c'est le cas des épreuves qui restent ouvertes plusieurs jours
   alors que tout le monde a déjà composé ;
2. la **date de fermeture** de l'évaluation ;
3. la **désactivation** de l'évaluation (« Fermer l'évaluation »), qui publie
   immédiatement, sans aucune date.

L'auto-correction n'y change rien : la note est calculée dès la remise, mais les
réponses attendent. Sans cette règle, le premier étudiant qui rend sa copie lit
les bonnes réponses pendant que la salle compose encore — ou les transmet par
message à ceux qui passent l'épreuve plus tard dans la journée.

Ce que l'étudiant voit en attendant : **sa note** (si « Afficher la note à la
fin » est cochée) et un encadré qui annonce la prochaine échéance en clair
(« le 30/09/2026 à 08:00 »). Le **récapitulatif PDF** et le **lien de suivi**
obéissent exactement à la même règle : le PDF se retéléchargeant avec la seule
référence, l'en exempter aurait suffi à tout divulguer.

Rien n'est supprimé ni recalculé : le détail reparaît de lui-même, sans aucune
action, à la première consultation qui suit la publication. La page « Résultats »
signale en tête quand les corrections ne sont pas encore publiées, avec la date
prévue ; les réglages rappellent la règle et préviennent lorsqu'**aucune date**
n'est fixée — dans ce cas, seule la désactivation de l'évaluation les publie.

### Confier la correction à des correcteurs externes

Quand une promotion entière a rendu, on ne corrige pas toujours seul.
**Résultats → Correcteurs** permet d'assigner une évaluation à un correcteur
qui **n'a aucun compte d'administration** : nom, email, délai (7 jours par
défaut), et l'application engendre pour lui une **référence de dix caractères**
(même alphabet que celle des étudiants, sans O/0 ni I/1/L) et un **lien
personnel** (`/correction/Ab12Cd34`).

- La page affiche le lien avec un bouton « Copier », son **QR code**, et une
  **fiche de mission PDF** à imprimer — QR code en haute résolution, consignes,
  échéance. Par défaut la fiche **ne porte pas la référence** : une fiche qui
  contiendrait le lien *et* la clé ouvrirait l'accès à elle seule. Le bouton
  « Fiche + référence » existe pour une remise en main propre.
- Le correcteur ouvre son lien, saisit **son email et sa référence** : le lien
  seul n'ouvre rien, donc le transférer ne donne aucun pouvoir de correction. Sa
  file ne contient que les copies des évaluations qui lui sont affectées — ni
  réglages, ni liens d'étudiant, ni remise à zéro. Il ne peut atteindre aucune
  page `/admin`, par construction : son espace a sa propre clé de session.
- **L'échéance se ferme toute seule**, sans tâche planifiée : elle est relue à
  chaque requête. La copie déjà ouverte reste enregistrable une dernière fois,
  toute nouvelle copie est refusée, l'administration voit « Échéance dépassée »
  et peut **prolonger** d'un clic (ce qui rouvre l'accès aussitôt), **suspendre**
  immédiatement, ou **régénérer le lien** si vous le jugez compromis.
- Le bouton « **Mes corrections (CSV)** » exporte **ses** notes et **ses**
  commentaires, et rien d'autre : une ligne par réponse qu'il a lui-même
  corrigée, dans ses seules évaluations. Aucune copie touchée par un autre
  correcteur ne s'y trouve. Évaluation anonyme : le nom de l'étudiant reste hors
  du fichier.
- Un correcteur n'est jamais supprimé, seulement suspendu : ses notes doivent
  garder leur auteur, et une note contestée a alors une réponse.

Le QR code de la fiche est recalculé à partir du lien à chaque affichage, jamais
enregistré : **régénérer le lien met le QR à jour tout seul**, un QR périmé est
donc impossible.

### Le second niveau : reprendre la note d'un correcteur

Une note posée par un correcteur externe n'est pas un point final. Depuis la
page de correction, l'administration peut la **reprendre** — et elle doit alors
dire **pourquoi** : un motif est exigé dès que la note change, sans quoi la trace
laissée ne dirait rien à celui qui la lirait plus tard.

- **La note remplacée est archivée, jamais effacée.** Le journal de la copie
  garde ce qui était enregistré (note, commentaire, auteur), qui a repris, quand
  et sur quel motif. C'est ce journal que la page de correction affiche sous
  chaque réponse rédigée.
- **La reprise est définitive du point de vue du correcteur.** La réponse
  s'affiche pour lui en **lecture seule**, avec la note retenue, le motif et son
  commentaire d'origine : plus de champ de note. S'il rejoue le formulaire à la
  main, l'enregistrement est refusé côté serveur — la décision de
  l'administration tient.
- **Confirmer est aussi une relecture.** Donner un motif sans changer la note
  journalise l'examen : la note reste au nom du correcteur qui l'a posée, et la
  relecture est tracée.
- **Chaque note contestée a un auteur.** « Note posée par Awa Kouassi
  (correcteur) », « par admin » : la page de correction et l'export CSV le disent.
- **Où le voir.** Résultats affiche « *n* note(s) revue(s) » sur la copie
  concernée, et la page Correcteurs compte, par correcteur, les notes que
  l'administration a reprises sur cette évaluation.
- **L'export du correcteur ne lui retire rien.** Sa note et son commentaire y
  restent tels qu'il les a posés ; deux colonnes donnent ce qui compte
  désormais (« Points retenus ») et la décision prise à sa place (« Révision »),
  motif compris.

Rien à configurer, aucune surcharge du serveur : contrôler une note coûte une
requête, et l'échéance du correcteur se ferme toujours sans tâche planifiée.

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
sortie subie).

**Le plein écran, concrètement.** Le W3C impose un geste de l'utilisateur pour
`requestFullscreen()`, et la navigation en fait tomber un (« whenever the
unloading document cleanup steps run, fully exit fullscreen ») : le plein écran ne
peut donc pas être transporté du formulaire de départ jusqu'à l'épreuve. Quand la
case est cochée, la page d'épreuve le rétablit donc **à la première action du
candidat** (clic, appui, touche), en l'annonçant par un bandeau qui propose aussi
de le déclencher tout de suite. Comme les réponses suivantes ne rechargent plus la
page, il tient ensuite jusqu'au bout. Si le candidat en sort lui-même (Échap ou
bouton), rien ne le lui réimpose.

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

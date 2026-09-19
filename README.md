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
- **Navigation linéaire** : une seule question affichée, réponse définitive, aucun retour en arrière possible
- **Correction automatique** : note calculée côté serveur, jamais envoyée avant la fin de l'épreuve
- **Récapitulatif PDF** : note, référence et temps utilisé, téléchargeable avec la seule référence
- **Surveillance (proctoring)** : blocage du copier-coller et du clic droit, plein écran proposé, journal horodaté des sorties de fenêtre
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
2. Cliquez sur "Copier le lien"
3. Partagez le lien avec les étudiants

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
3. **Préparer les références** : soit en important la liste des étudiants
   (nom, email, filière), soit en générant un nombre de références. Chaque
   référence fait 10 caractères, sans lettres ambiguës (ni O/0, ni I/1/L). Dès
   qu'une référence existe, l'évaluation demande la référence au lieu du nom.
   Le bouton « Télécharger la liste (CSV) » donne les références à distribuer.
4. **Ouvrir l'évaluation** puis partager le lien `/q/{jeton}`.
5. **Suivre les résultats** (« Résultats ») : note, barème, temps passé, nombre de
   sorties de fenêtre. Export CSV possible, réinitialisation d'une participation
   en cas d'incident.


Côté étudiant : saisie de la référence, une question à la fois, temps affiché et
rappelé par le serveur, remise de la copie ou rendu automatique à l'expiration.
La note, la référence et le temps apparaissent immédiatement, avec un
récapitulatif PDF retéléchargeable à tout moment avec la référence seule.

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
plein écran proposé, chaque perte de focus horodatée et comptée — et un chrono
que le candidat ne peut pas manipuler, puisqu'il est vérifié à chaque requête.

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

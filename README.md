# Iziwork - Gestion de dépôts de travaux

Application web pour la collecte et la gestion des travaux rendus par les étudiants.

## 🚀 Fonctionnalités

### Admin
- **Tableau de bord** : Vue d'ensemble des formulaires et soumissions
- **Gestion des formulaires** : Création, modification, activation/désactivation
- **Paramètres** : Dates d'ouverture/fermeture, nombre max de dépôts, anonymat
- **Gestion des soumissions** : Consultation, téléchargement individuel ou en lot (ZIP)
- **Sécurité** : Authentification par session, mot de passe haché

### Étudiant
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

Guide complet pas-à-pas (SSH, sous-domaine, MySQL, HTTPS, mises à jour) :
**[DEPLOYMENT.md](DEPLOYMENT.md)**. Un template d'environnement de production est
fourni dans [`.env.production.example`](.env.production.example).

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

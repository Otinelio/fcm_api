MODULE 6 — Connecter Laravel à PostgreSQL
1. Objectif du module

Configurer ton projet Laravel pour se connecter au conteneur PostgreSQL créé dans les modules précédents, et vérifier que les migrations passent correctement.

2. Concept à comprendre

Laravel abstrait la base de données via config/database.php : chaque type de connexion (mysql, pgsql, sqlite...) a sa propre configuration, et DB_CONNECTION dans le .env détermine laquelle est active. Le driver PHP nécessaire pour PostgreSQL est pdo_pgsql (l'équivalent de pdo_mysql que tu utilises actuellement) — sans lui, Laravel ne peut tout simplement pas ouvrir de connexion, peu importe si la config est correcte.

Une fois DB_CONNECTION=pgsql actif, la majorité de ton code Eloquent existant fonctionne sans modification — le Query Builder traduit automatiquement en SQL PostgreSQL valide. Les points d'attention se limitent au SQL brut (DB::statement(...)) que tu aurais éventuellement écrit avec une syntaxe spécifique à MySQL.

3. Actions exactes à réaliser
Vérifier/installer l'extension PHP pdo_pgsql.
Configurer les variables .env pour pointer vers le conteneur PostgreSQL.
Vérifier config/database.php.
Tester la connexion depuis Tinker.
Lancer les migrations sur la nouvelle base.
4. Fichiers à créer ou modifier
.env
.env.example
config/database.php (vérification, généralement déjà correct par défaut dans Laravel)
5. Commandes à exécuter

Vérifier l'extension PHP :

bash
php -m | grep pgsql

Si absente (exemple Ubuntu/Debian avec PHP 8.3) :

bash
sudo apt update
sudo apt install php8.3-pgsql
sudo systemctl restart php8.3-fpm   # si tu utilises PHP-FPM

Sur macOS avec Homebrew :

bash
brew install php
# pdo_pgsql est généralement inclus par défaut avec Homebrew PHP récent

Tester la connexion :

bash
php artisan tinker

Dans Tinker :

php
DB::connection()->getPdo();

Puis lancer les migrations :

bash
php artisan migrate
6. Code à écrire

.env :

env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=restaurant_loyalty
DB_USERNAME=loyalty_user
DB_PASSWORD=loyalty_secret

config/database.php (vérifier que cette entrée existe déjà — c'est le cas par défaut dans une installation Laravel standard, mais à confirmer) :

php
'pgsql' => [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
],
7. Résultat attendu
DB::connection()->getPdo(); dans Tinker retourne un objet PDO sans erreur.
php artisan migrate exécute toutes tes migrations avec succès sur la base PostgreSQL.
8. Comment vérifier que ça fonctionne
bash
php artisan migrate:status

Doit afficher toutes tes migrations avec le statut Ran. Confirme aussi dans pgAdmin ou psql (\dt) que les tables sont bien apparues dans restaurant_loyalty.

9. Erreurs courantes à éviter
Oublier d'installer pdo_pgsql → erreur could not find driver au moment de la première requête, alors que la configuration .env semble pourtant correcte.
Laisser DB_CONNECTION=mysql par erreur après avoir rempli les variables PostgreSQL — Laravel continue silencieusement d'utiliser MySQL tant que cette clé n'est pas changée.
Ne pas vider le cache de config après modification du .env : php artisan config:clear.
Confondre le port 5432 (PostgreSQL) avec l'ancien 3306 (MySQL) resté par erreur dans le .env.
Si Laravel tourne lui-même dans un conteneur Docker séparé (Sail, ou ton propre setup), utiliser 127.0.0.1 comme host ne fonctionnera pas — il faut alors utiliser le nom du service Docker (postgres) comme host, exactement comme pour pgAdmin au Module 4.
10. Checklist de validation avant de passer au module suivant
 pdo_pgsql est installé et actif (php -m | grep pgsql).
 DB::connection()->getPdo() fonctionne sans erreur dans Tinker.
 php artisan migrate s'exécute avec succès sur PostgreSQL.
 Les tables migrées sont visibles dans pgAdmin et/ou psql.
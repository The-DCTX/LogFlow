#!/bin/bash
set -e

# Génère config.php depuis l'exemple si absent (il lit les variables d'env)
if [ ! -f /var/www/html/config.php ]; then
    cp /var/www/html/config.php.example /var/www/html/config.php
    chown www-data:www-data /var/www/html/config.php
fi

# Attend que MariaDB accepte les connexions avant de démarrer Apache
echo "LogFlow : attente de la base de données (${DB_HOST:-db})…"
until php -r '
    try {
        new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_NAME"),
                getenv("DB_USER"), getenv("DB_PASS"));
        exit(0);
    } catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    sleep 2
done
echo "LogFlow : base de données prête."

# Applique les migrations de schéma en attente (idempotent, voir migrations/)
php /var/www/html/migrate.php || { echo "LogFlow : migration échouée, arrêt."; exit 1; }

# Initialise les réglages issus de l'environnement (ex. SERVER_URL) au 1er démarrage
php /var/www/html/docker/init-config.php || true

exec "$@"

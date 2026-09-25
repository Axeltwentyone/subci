#!/bin/sh
set -e
cd /var/www/html

sed -i "s/__PORT__/${PORT:-10000}/" /etc/nginx/http.d/default.conf

# Configuration figée à partir des variables d'environnement Render.
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Base de données : migrations, puis catalogue des services (idempotent, jamais les données de démo).
php artisan migrate --force
php artisan db:seed --class=ServiceSeeder --force

chown -R www-data:www-data storage bootstrap/cache
exec "$@"

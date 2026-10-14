#!/bin/bash
set -e

# Ensure runtime directories exist
mkdir -p storage/framework/{cache,sessions,views} storage/logs
DB_PATH="${DB_DATABASE:-database/database.sqlite}"
DB_DIR=$(dirname "$DB_PATH")
mkdir -p "$DB_DIR"
if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
fi

# Ensure permissions
chown -R www-data:www-data storage bootstrap/cache "$DB_DIR"
chmod -R 775 storage bootstrap/cache "$DB_DIR"

# Cache config, routes, views for production performance
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Run pending database migrations
if [ "${SKIP_MIGRATIONS}" != "true" ]; then
    php artisan migrate --force || true
fi

# Ensure public/storage symlink exists
php artisan storage:link || true

exec "$@"

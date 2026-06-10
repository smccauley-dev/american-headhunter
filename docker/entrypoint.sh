#!/bin/bash
set -e

# Fix storage permissions on every start.
# On Windows volumes, ownership resets to root on container restart.
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Publish Filament JS/CSS assets when missing (safe to run on every start).
if [ ! -f /var/www/html/public/css/filament/filament/app.css ]; then
    php artisan filament:assets --quiet || true
fi

exec "$@"

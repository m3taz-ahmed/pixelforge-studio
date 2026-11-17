#!/usr/bin/env bash
set -e

# Render injects $PORT; Apache must listen on it.
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# One free container runs web + queue worker + scheduler.
php artisan queue:work --sleep=1 --tries=3 --daemon &
php artisan schedule:work &

exec apache2-foreground

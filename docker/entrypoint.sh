#!/bin/sh
# Entrypoint fuer app, worker und scheduler.
# Aufgaben: Storage-Verzeichnisse sicherstellen, Konfig- und Routen-Cache aufbauen.
# Migrationen laufen NICHT automatisch; sie werden bewusst per deploy.sh oder
# "docker compose run --rm app php artisan migrate --force" ausgefuehrt (Datenintegritaet).
set -eu

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY fehlt. Abbruch." >&2
    exit 1
fi

# Caches nur fuer die Laufzeitprozesse, nicht fuer einmalige artisan-Aufrufe (z. B. migrate)
case "${1:-}" in
    php-fpm|php)
        php artisan config:cache --no-ansi >/dev/null
        php artisan route:cache --no-ansi >/dev/null
        php artisan view:cache --no-ansi >/dev/null
        php artisan event:cache --no-ansi >/dev/null
        ;;
esac

exec "$@"

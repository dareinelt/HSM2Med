#!/bin/sh
# Container-Start: Verzeichnisse vorbereiten, PHP-Limits setzen, DB-Migrationen ausfuehren.
set -eu

APP_DATA_DIR="${APP_DATA_DIR:-/var/www/storage}"
IMPORT_DATA_DIR="${IMPORT_DATA_DIR:-/data/imports}"

for dir in "$APP_DATA_DIR" "$APP_DATA_DIR/logs" "$APP_DATA_DIR/sessions" "$APP_DATA_DIR/pending" "$IMPORT_DATA_DIR"; do
    mkdir -p "$dir"
    chown www-data:www-data "$dir"
    chmod 0750 "$dir"
done

# Upload-Limits aus UPLOAD_MAX_SIZE ableiten
php /var/www/html/bin/php-limits.php > "$PHP_INI_DIR/conf.d/zz-hsm2med-limits.ini"

if [ "${1:-}" = "apache2-foreground" ] && [ "${SKIP_MIGRATIONS:-0}" != "1" ]; then
    runuser -u www-data -- php /var/www/html/bin/migrate.php --wait=120
    # Administratorkonto aus der .env anlegen, falls noch keines existiert (idempotent).
    runuser -u www-data -- php /var/www/html/bin/seed-admin.php
fi

exec docker-php-entrypoint "$@"

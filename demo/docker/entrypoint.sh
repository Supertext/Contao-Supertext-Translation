#!/bin/sh
# Demo start: database URL, migrations, demo accounts and content, then Apache.
set -e
cd /var/www/html

# Railway's MySQL/MariaDB services expose their connection as separate variables.
if [ -z "$DATABASE_URL" ]; then
    host="${MYSQLHOST:-${MARIADB_HOST:-}}"
    if [ -n "$host" ]; then
        export DATABASE_URL="mysql://${MYSQLUSER:-${MARIADB_USER:-root}}:${MYSQLPASSWORD:-${MARIADB_PASSWORD:-}}@${host}:${MYSQLPORT:-${MARIADB_PORT:-3306}}/${MYSQLDATABASE:-${MARIADB_DATABASE:-contao}}"
    fi
fi

if [ -z "$DATABASE_URL" ]; then
    echo "DATABASE_URL (or MYSQLHOST etc.) is not set" >&2
    exit 1
fi

if [ -z "$APP_SECRET" ]; then
    echo "APP_SECRET is not set" >&2
    exit 1
fi

# Apache's "Listen" uses the PORT variable; it must be visible to Apache.
echo "export PORT=${PORT:-8080}" >> /etc/apache2/envvars

echo "Waiting for the database..."
i=0
until php -r '$u = parse_url(getenv("DATABASE_URL")); new PDO(sprintf("mysql:host=%s;port=%d", $u["host"], $u["port"] ?? 3306), urldecode($u["user"] ?? ""), urldecode($u["pass"] ?? ""));' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "Database not reachable after 120 s" >&2
        exit 1
    fi
    sleep 2
done

php vendor/bin/contao-console contao:migrate --no-interaction --no-backup
php vendor/bin/contao-console supertext:demo:setup --no-interaction
php vendor/bin/contao-console cache:clear --no-warmup
php vendor/bin/contao-console cache:warmup
# The commands above ran as root; Apache runs as www-data and needs these writable
# (Symfony keeps its lock files in /tmp/symfony-lock).
mkdir -p /tmp/symfony-lock
chown -R www-data:www-data var public files system assets /tmp/symfony-lock 2>/dev/null || true

exec apache2-foreground

#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Only the app container bootstraps the install; the queue worker and scheduler
# share the same volume and would race each other.
if [ "${BLOCK_RADAR_BOOTSTRAP:-false}" = "true" ]; then
    if [ ! -f .env ]; then
        echo "==> No .env found, copying .env.example"
        cp .env.example .env
    fi

    if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
        echo "==> Installing Composer dependencies"
        composer install --no-interaction --prefer-dist
    fi

    if ! grep -qE '^APP_KEY=.+' .env; then
        echo "==> Generating application key"
        php artisan key:generate --force
    fi

    mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache

    # Checked over PDO rather than a mysql client: Alpine ships MariaDB's
    # client, which rejects MySQL 8.4's self-signed TLS certificate. This also
    # proves the exact credentials Laravel is about to use.
    echo "==> Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}"
    attempts=0
    until php -r '
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s",
            getenv("DB_HOST") ?: "mysql",
            getenv("DB_PORT") ?: "3306",
            getenv("DB_DATABASE") ?: "block_radar"
        );
        try {
            new PDO($dsn, getenv("DB_USERNAME") ?: "block_radar", getenv("DB_PASSWORD") ?: "secret");
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    '; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "!! MySQL did not become reachable after 120s" >&2
            exit 1
        fi
        sleep 2
    done

    echo "==> Running migrations"
    php artisan migrate --force

    php artisan config:clear
fi

# The queue and scheduler containers gate on this via the php healthcheck, so
# they never start against a half-installed vendor/ directory.
touch /tmp/block-radar-ready

exec "$@"

#!/bin/sh
# Prepares the container before starting php-fpm, the messenger worker or the scheduler.
set -e

fail() { echo "FATAL: $*" >&2; exit 1; }

if [ "$1" = "php-fpm" ] || [ "$1" = "php" ] || [ "$1" = "bin/console" ]; then
    # Refuse to run production with placeholder or weak secrets.
    if [ "${APP_ENV:-prod}" = "prod" ]; then
        for var in APP_SECRET JWT_PASSPHRASE; do
            value=$(printenv "$var" || true)
            case "$value" in
                ''|*change_me*|*dev_only*|*ChangeMe*) fail "$var is empty or still a placeholder. Generate one with: openssl rand -hex 32" ;;
            esac
            [ "${#value}" -ge 32 ] || fail "$var must be at least 32 characters long."
        done
        [ "${JWT_COOKIE_SECURE:-1}" = "1" ] || echo "WARNING: JWT_COOKIE_SECURE=0, session cookies will travel over plain HTTP." >&2
    fi

    # JWT keys: mounted secrets in production; generated once into the shared volume otherwise.
    if [ ! -f config/jwt/private.pem ]; then
        php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
    fi
    chmod 600 config/jwt/private.pem 2>/dev/null || true

    echo "Waiting for the database..."
    until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do sleep 1; done

    # Only the API container migrates, so concurrent replicas/workers never race.
    if [ "$1" = "php-fpm" ] && [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
        if [ "${LOAD_DEMO_DATA:-0}" = "1" ] && [ "$(php bin/console dbal:run-sql -q 'SELECT COUNT(*) FROM organization' 2>/dev/null | grep -o '[0-9]\+' | tail -1)" = "0" ]; then
            echo "WARNING: loading DEMO accounts with a publicly documented password. Never enable LOAD_DEMO_DATA on a reachable deployment." >&2
            APP_ENV=dev composer install --no-interaction --no-progress -q && APP_ENV=dev php bin/console doctrine:fixtures:load --append --no-interaction
        fi
    fi
    chown -R www-data:www-data var config/jwt 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"

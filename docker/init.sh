#!/usr/bin/env sh
# One-shot initialisation run by the `init` compose service before the app,
# workers, and scheduler start. Idempotent: safe on every `docker compose up`.
set -eu

cd /app

if [ ! -f .env ]; then
    echo "[init] creating .env from .env.example"
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
    echo "[init] composer install"
    composer install --no-interaction --prefer-dist
fi

if [ ! -d node_modules ] || [ package-lock.json -nt node_modules ]; then
    echo "[init] npm ci"
    npm ci --no-audit --no-fund
    touch node_modules
fi

if [ ! -f public/build/manifest.json ] || [ -n "$(find resources -newer public/build/manifest.json -type f 2>/dev/null | head -n 1)" ]; then
    echo "[init] building frontend assets"
    npm run build
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "[init] generating APP_KEY"
    php artisan key:generate --force --no-interaction
fi

echo "[init] running migrations"
php artisan migrate --force --no-interaction

echo "[init] ensuring local object-storage buckets"
php artisan noise:storage:ensure-buckets

if [ -n "${NOISE_INITIAL_OWNER_EMAIL:-}" ]; then
    php artisan noise:create-owner "$NOISE_INITIAL_OWNER_EMAIL" --if-none --account="${NOISE_INITIAL_ACCOUNT:-Household}" --property="${NOISE_INITIAL_PROPERTY:-Home}" --no-interaction
fi

echo "[init] done — Noise Monitor will be available at ${APP_URL:-http://localhost:8088}"

#!/usr/bin/env sh
# Convenience wrapper: build the image, start the stack, and wait for the
# one-shot `init` service (dependencies, assets, key, migrations, buckets).
# Plain `docker compose up -d` does the same thing.
set -eu

cd "$(dirname "$0")/.."

docker compose up -d --build

echo "Waiting for first-boot initialisation (composer/npm on a fresh checkout can take a few minutes)…"
docker compose wait init >/dev/null 2>&1 || true
docker compose logs --no-log-prefix init | tail -n 20

echo
echo "Noise Monitor: http://localhost:${FORWARD_APP_PORT:-8088}"
echo "No owner yet? docker compose exec app php artisan noise:create-owner you@example.com --account=Household --property=Home"

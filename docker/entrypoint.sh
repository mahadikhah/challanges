#!/bin/sh
#
# Container entrypoint for the app / scheduler / queue services.
#
# Runs for every container built from the `app` target: the scheduler and two
# queue workers start alongside the web app. Everything below is idempotent
# because they boot concurrently and share the storage volumes.
#
# Why this exists at all: the Dockerfile creates storage/framework/* at build
# time, but deploy/compose.app.yml mounts named volumes over parts of storage/ —
# and a fresh named volume is EMPTY. Without this, the first boot of a new
# environment dies on "Please provide a valid cache path" from the view compiler.
#
# Note deliberately NOT shared as a volume: bootstrap/cache and
# storage/framework. Each container writes its own config/route/view cache from
# its own image layer. Sharing them would mean four containers racing on
# config:cache at startup, where one can read a half-written file another is
# still emitting.

set -e

cd /var/www/html

# 1. Rebuild the directory skeleton the volumes may have hidden.
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# 2. Public disk symlink. `storage:link` is not used here because it fails when
#    the link already exists, and this runs on every container start.
if [ ! -e public/storage ]; then
    ln -sfn /var/www/html/storage/app/public public/storage
fi

# 3. Config caching. Only outside `local`, and only once an APP_KEY exists —
#    skipping it otherwise keeps `docker run ... php -v` style introspection
#    working before an environment is fully configured.
if [ "${APP_ENV:-production}" != "local" ] && [ -n "${APP_KEY:-}" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

exec "$@"

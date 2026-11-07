#!/usr/bin/env bash
#
# Sail, for a VM that has no PHP and therefore no `sail` binary.
#
# `vendor/bin/sail` is itself a composer-installed file, so on a Docker-only VM
# it does not exist until composer has run — and composer only runs inside the
# container it would have started. This script is the way in.
#
#   deploy/dev.sh setup             # first run: install deps, key, migrate
#   deploy/dev.sh up                # start the stack
#   deploy/dev.sh artisan migrate   # any artisan command
#   deploy/dev.sh composer require …
#   deploy/dev.sh npm run dev       # Vite dev server (binds VITE_PORT)
#   deploy/dev.sh test              # the composer test gate
#   deploy/dev.sh shell             # interactive shell in the container
#   deploy/dev.sh down
#
# Once `setup` has run, `vendor/bin/sail` exists and can be used directly if
# preferred — but it reads the root compose.yaml, which builds its own image
# rather than using the published one. Sticking to this script keeps the VM on
# the CI-built image.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "Neither 'docker compose' nor 'docker-compose' is available." >&2
    exit 1
fi

# Sail's entrypoint builds a container user with this uid/gid so files created
# inside the container are owned by the developer on the host, not by root.
WWWUSER="${WWWUSER:-$(id -u)}"
WWWGROUP="${WWWGROUP:-$(id -g)}"
export WWWUSER WWWGROUP

# --project-directory is load-bearing: the bind mounts in compose.dev.yml are
# written relative to the repo root, not to deploy/.
compose() {
    "${COMPOSE[@]}" \
        --project-directory "$REPO_ROOT" \
        -f "$REPO_ROOT/deploy/compose.dev.yml" \
        -p challenges-dev \
        "$@"
}

# Run as the mapped user rather than root, so generated files (vendor/,
# node_modules/, wayfinder output) do not end up root-owned on the host.
in_container() {
    compose exec -u "sail" laravel.test "$@"
}

ensure_env() {
    if [ ! -f "$REPO_ROOT/.env" ]; then
        echo "==> Creating .env from .env.example"
        cp "$REPO_ROOT/.env.example" "$REPO_ROOT/.env"
    fi
}

ensure_up() {
    if ! compose ps --services --filter status=running 2>/dev/null | grep -q laravel.test; then
        echo "==> Stack is not running; starting it"
        compose up -d
        # Sail's entrypoint has to create the user and start supervisor before
        # exec will succeed.
        sleep 5
    fi
}

COMMAND="${1:-}"
shift || true

case "$COMMAND" in
    setup)
        ensure_env
        echo "==> Pulling the published sail image"
        compose pull
        compose up -d
        sleep 5
        echo "==> Installing composer dependencies"
        in_container composer install
        echo "==> Generating APP_KEY (no-op if .env already has one)"
        in_container php artisan key:generate
        echo "==> Installing npm dependencies"
        in_container npm install
        echo "==> Building frontend assets"
        in_container npm run build
        echo "==> Migrating"
        in_container php artisan migrate --force
        echo
        echo "Ready. http://localhost:${APP_PORT:-80}"
        echo "For live asset rebuilds: deploy/dev.sh npm run dev"
        ;;

    up)
        ensure_env
        compose up -d "$@"
        ;;

    down)
        compose down "$@"
        ;;

    restart)
        compose restart "$@"
        ;;

    logs)
        compose logs -f "$@"
        ;;

    ps|status)
        compose ps
        ;;

    artisan)
        ensure_up
        in_container php artisan "$@"
        ;;

    composer)
        ensure_up
        in_container composer "$@"
        ;;

    npm)
        ensure_up
        in_container npm "$@"
        ;;

    test)
        ensure_up
        # The full quality gate from CLAUDE.md: pint + phpstan + artisan test.
        in_container composer test
        ;;

    shell|bash)
        ensure_up
        in_container bash
        ;;

    ''|-h|--help)
        sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'
        ;;

    *)
        # Anything unrecognised is passed straight through to the container,
        # matching how `sail` behaves.
        ensure_up
        in_container "$COMMAND" "$@"
        ;;
esac

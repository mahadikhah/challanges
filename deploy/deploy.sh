#!/usr/bin/env bash
#
# Deploy one environment. The only command an operator needs on the VM.
#
#   deploy/deploy.sh staging                 # latest images, git pull first
#   deploy/deploy.sh production v1.4.0       # a specific tag
#   deploy/deploy.sh production --no-pull    # redeploy without touching git
#   deploy/deploy.sh staging --rollback      # back to the last known-good tag
#
# Assumes nothing on the host but Docker and git. Every PHP/composer/node
# command runs inside a container.
#
# ─── ROLLBACK DOES NOT UNDO MIGRATIONS ──────────────────────────────────────
# Step 4 migrates before traffic moves, so a rollback returns the CODE to the
# previous tag while the SCHEMA stays forward. That is safe for additive
# migrations (new table, new nullable column) and NOT safe for destructive ones
# (dropped or renamed column, narrowed type). If a release contains a
# destructive migration, it cannot be rolled back by this script — restore the
# pre-deploy database dump instead. Step 4 prints a reminder to take one.
# ────────────────────────────────────────────────────────────────────────────

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------

ENVIRONMENT="${1:-}"
shift || true

REQUESTED_TAG=''
DO_PULL=1
DO_ROLLBACK=0

while [ $# -gt 0 ]; do
    case "$1" in
        --no-pull) DO_PULL=0 ;;
        --rollback) DO_ROLLBACK=1 ;;
        -h|--help) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "Unknown option: $1" >&2; exit 2 ;;
        *) REQUESTED_TAG="$1" ;;
    esac
    shift
done

case "$ENVIRONMENT" in
    staging|production) ;;
    *)
        echo "Usage: deploy/deploy.sh <staging|production> [tag] [--no-pull] [--rollback]" >&2
        exit 2
        ;;
esac

ENV_FILE="$REPO_ROOT/deploy/${ENVIRONMENT}.env"
PROJECT="challenges-${ENVIRONMENT}"
STATE_GOOD="$REPO_ROOT/deploy/${ENVIRONMENT}.last-good"
STATE_FAILED="$REPO_ROOT/deploy/${ENVIRONMENT}.failed"

if [ ! -f "$ENV_FILE" ]; then
    echo "Missing $ENV_FILE — copy deploy/${ENVIRONMENT}.env.example and fill it in." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Compose v1/v2 shim
# ---------------------------------------------------------------------------
# Docker Engine 20.10 ships compose v1 as `docker-compose`; 24+ ships v2 as the
# `docker compose` subcommand. Both are supported, so nothing here may use
# v2-only syntax. (This is also why queue scaling goes through `--scale` rather
# than `deploy.replicas`, which v1 silently ignores.)

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "Neither 'docker compose' nor 'docker-compose' is available." >&2
    exit 1
fi

log() { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$1" >&2; }
fail() { printf '\033[1;31m[fail]\033[0m %s\n' "$1" >&2; }

# ---------------------------------------------------------------------------
# 1. Refresh the working tree
# ---------------------------------------------------------------------------
# Only the compose files, Caddyfile and this script come from git — the
# application itself arrives as a published image. --ff-only so a VM with local
# edits fails loudly instead of dropping into a merge.

if [ "$DO_PULL" -eq 1 ]; then
    log "Updating working tree"
    git pull --ff-only
else
    log "Skipping git pull (--no-pull)"
fi

# ---------------------------------------------------------------------------
# 2. Resolve the image tag
# ---------------------------------------------------------------------------

if [ "$DO_ROLLBACK" -eq 1 ]; then
    if [ ! -f "$STATE_GOOD" ]; then
        fail "No recorded last-good tag for ${ENVIRONMENT}; nothing to roll back to."
        exit 1
    fi
    IMAGE_TAG="$(cat "$STATE_GOOD")"
    log "Rolling back to ${IMAGE_TAG}"
elif [ -n "$REQUESTED_TAG" ]; then
    IMAGE_TAG="$REQUESTED_TAG"
else
    IMAGE_TAG='latest'
fi

# DEPLOY_DOMAIN, QUEUE_WORKERS and EDGE_ALIAS are needed by this script, not
# just by the containers. Sourced in a subshell-safe way: `set -a` exports
# every assignment so the compose calls below inherit them.
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

: "${DEPLOY_DOMAIN:?DEPLOY_DOMAIN must be set in $ENV_FILE}"
QUEUE_WORKERS="${QUEUE_WORKERS:-2}"

# compose.app.yml reads these two.
export IMAGE_TAG
export APP_ENV_FILE="$ENV_FILE"

compose() {
    "${COMPOSE[@]}" -p "$PROJECT" \
        -f "$REPO_ROOT/deploy/compose.app.yml" \
        --env-file "$ENV_FILE" \
        "$@"
}

log "Deploying ${ENVIRONMENT} @ ${IMAGE_TAG} (${DEPLOY_DOMAIN}, ${QUEUE_WORKERS} worker(s))"

# ---------------------------------------------------------------------------
# 3. Fetch the images
# ---------------------------------------------------------------------------
# Before anything is torn down: a tag that does not exist in the registry must
# fail here, with the old release still serving traffic.
#
# --ignore-pull-failures so an already-cached mysql:8.4 does not make the whole
# deploy depend on reaching Docker Hub. That leniency is then undone by the
# explicit check below, which is what actually enforces that this deploy's own
# images are present.

log "Pulling images"
compose pull --ignore-pull-failures app web mysql

REGISTRY="${IMAGE_REGISTRY:-ghcr.io/mahadikhah/challanges}"
for image in "${REGISTRY}/app:${IMAGE_TAG}" "${REGISTRY}/web:${IMAGE_TAG}"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        fail "Image '${image}' is not available locally or in the registry."
        echo "  Check the tag exists: https://github.com/mahadikhah/challanges/pkgs/container/challanges%2Fapp" >&2
        exit 1
    fi
done

# ---------------------------------------------------------------------------
# 4. Migrate
# ---------------------------------------------------------------------------
# On the NEW image, before traffic moves, so a migration failure aborts the
# deploy with the previous release still up. `run --rm` starts mysql and waits
# on its healthcheck via depends_on.
#
# The retry exists because `mysqladmin ping` answers before MySQL will accept
# application connections — on a first deploy, where the container is also
# initialising the data directory, the gap is tens of seconds.

log "Running migrations"
warn "Back up first if this release drops or renames a column — rollback does not revert schema."

MIGRATED=0
for attempt in 1 2 3 4 5; do
    if compose run --rm app php artisan migrate --force; then
        MIGRATED=1
        break
    fi
    warn "Migration attempt ${attempt}/5 failed; database may still be initialising. Retrying in 10s."
    sleep 10
done

if [ "$MIGRATED" -ne 1 ]; then
    fail "Migrations failed after 5 attempts. The previous release is still serving; nothing was switched."
    exit 1
fi

# ---------------------------------------------------------------------------
# 5. Switch traffic
# ---------------------------------------------------------------------------
# --remove-orphans cleans up containers from a previous QUEUE_WORKERS value.
#
# No `queue:restart` afterwards, deliberately: `up -d` recreates the worker
# containers on the new image, so they are already running new code. Issuing
# queue:restart here would write a timestamp that the just-started workers read
# as "newer than me" and exit for no reason.

log "Starting containers"
compose up -d --remove-orphans --scale "queue=${QUEUE_WORKERS}"

# ---------------------------------------------------------------------------
# 6. Health check
# ---------------------------------------------------------------------------
# Probed from inside the web container, not from the host: it needs no curl on
# the VM, and it sidesteps VMs whose NAT will not let them reach their own
# public IP. It covers nginx → fastcgi → Laravel → database, which is what a
# deploy actually changes; Caddy's routing is static and verified once at setup.

log "Waiting for /up"
HEALTHY=0
for attempt in $(seq 1 30); do
    if compose exec -T web curl -fsS -o /dev/null --max-time 5 http://localhost/up 2>/dev/null; then
        HEALTHY=1
        echo "Healthy after ${attempt} attempt(s)."
        break
    fi
    sleep 2
done

if [ "$HEALTHY" -ne 1 ]; then
    fail "Health check failed for ${ENVIRONMENT} @ ${IMAGE_TAG}."
    echo "$IMAGE_TAG" > "$STATE_FAILED"

    compose logs --tail 40 app web || true

    if [ "$DO_ROLLBACK" -eq 1 ]; then
        fail "This WAS the rollback attempt — not looping. Investigate manually."
        exit 1
    fi

    if [ ! -f "$STATE_GOOD" ]; then
        fail "No last-good tag recorded (first deploy?). Leaving the stack up for inspection."
        exit 1
    fi

    PREVIOUS="$(cat "$STATE_GOOD")"
    if [ "$PREVIOUS" = "$IMAGE_TAG" ]; then
        fail "Last-good tag is the one that just failed. Investigate manually."
        exit 1
    fi

    # --rollback re-reads the tag from the last-good file, so the tag is not
    # passed again here: one source of truth, no chance of the two disagreeing.
    warn "Rolling back to ${PREVIOUS}"
    exec "$0" "$ENVIRONMENT" --no-pull --rollback
fi

# ---------------------------------------------------------------------------
# 7. Record success
# ---------------------------------------------------------------------------

echo "$IMAGE_TAG" > "$STATE_GOOD"
rm -f "$STATE_FAILED"

log "Deployed ${ENVIRONMENT} @ ${IMAGE_TAG}"
echo "   https://${DEPLOY_DOMAIN}"
echo
echo "   Logs:    ${COMPOSE[*]} -p ${PROJECT} -f deploy/compose.app.yml logs -f app"
echo "   Status:  ${COMPOSE[*]} -p ${PROJECT} -f deploy/compose.app.yml ps"
echo
echo "   If this is a first deploy, register the webhooks now — see"
echo "   docs/setup-vm-docker.md §6."

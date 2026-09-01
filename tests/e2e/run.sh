#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
IMAGE="${STASHD_E2E_IMAGE:-stashd:e2e}"
PG_IMAGE="${STASHD_E2E_PG_IMAGE:-docker.io/library/postgres:18-alpine}"
NAME="stashd-e2e-$$"
PG_NAME="stashd-e2e-pg-$$"
NETWORK="stashd-e2e-net-$$"
PG_DB=stashd
PG_USER=stashd
PG_PASSWORD=stashd-e2e
TIMEOUT="${STASHD_E2E_TIMEOUT:-180}"
SKIP_BUILD="${STASHD_E2E_SKIP_BUILD:-0}"
TMP="$(mktemp -d)"
PUID="$(id -u)"
PGID="$(id -g)"

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker rm -f "$PG_NAME" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

mkdir -p "$TMP/data" "$TMP/media"
if [ "$SKIP_BUILD" != "1" ]; then
    docker build -t "$IMAGE" "$ROOT"
fi

# PostgreSQL is the default runtime, so the app cannot boot without one.
docker network create "$NETWORK" >/dev/null 2>&1 || true
docker run -d --name "$PG_NAME" --network "$NETWORK" --network-alias postgres \
    -e POSTGRES_DB="$PG_DB" \
    -e POSTGRES_USER="$PG_USER" \
    -e POSTGRES_PASSWORD="$PG_PASSWORD" \
    "$PG_IMAGE" >/dev/null

pg_deadline=$(( $(date +%s) + TIMEOUT ))
while :; do
    if docker exec "$PG_NAME" pg_isready -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1; then
        break
    fi
    if [ "$(date +%s)" -ge "$pg_deadline" ]; then
        echo "e2e failed: PostgreSQL not ready within ${TIMEOUT}s" >&2
        docker logs "$PG_NAME" 2>&1 || true
        exit 1
    fi
    sleep 2
done

docker run -d --name "$NAME" --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data -e STASHD_MEDIA_PATH=/media \
    -e PUID="$PUID" -e PGID="$PGID" \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=postgres \
    -e DB_PORT=5432 \
    -e DB_DATABASE="$PG_DB" \
    -e DB_USERNAME="$PG_USER" \
    -e DB_PASSWORD="$PG_PASSWORD" \
    -v "$TMP/data:/data" -v "$TMP/media:/media" -p 18475:8474 "$IMAGE" >/dev/null

# Bounded, so a container that never becomes healthy fails loudly instead of
# hanging the job until the workflow timeout.
health_deadline=$(( $(date +%s) + TIMEOUT ))
until curl -fsS http://127.0.0.1:18475/health >/dev/null 2>&1; do
    if [ "$(date +%s)" -ge "$health_deadline" ]; then
        echo "e2e failed: health endpoint not ready within ${TIMEOUT}s" >&2
        docker logs "$NAME" 2>&1 || true
        exit 1
    fi
    sleep 2
done

STASHD_BASE_URL=http://127.0.0.1:18475 npm run test

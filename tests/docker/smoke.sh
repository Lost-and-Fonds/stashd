#!/usr/bin/env sh
# Docker smoke test — release gate starter.
# First run: composer test:docker-smoke
# Reuse image later: STASHD_SMOKE_SKIP_BUILD=1 composer test:docker-smoke
# If composer cannot see docker/podman, run tests/docker/smoke.sh directly.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
IMAGE="${STASHD_SMOKE_IMAGE:-stashd:smoke}"
SKIP_BUILD="${STASHD_SMOKE_SKIP_BUILD:-0}"
TIMEOUT="${STASHD_SMOKE_TIMEOUT:-180}"
NAME="stashd-smoke-$$"
PG_NAME="stashd-smoke-pg-$$"
NETWORK="stashd-smoke-net-$$"
PG_IMAGE="${STASHD_SMOKE_PG_IMAGE:-docker.io/library/postgres:18-alpine}"
PG_DB=stashd
PG_USER=stashd
PG_PASSWORD=stashd-smoke
TMP="$(mktemp -d)"
PUID="$(id -u)"
PGID="$(id -g)"

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    CONTAINER=docker
elif command -v podman >/dev/null 2>&1; then
    CONTAINER=podman
else
    echo "smoke failed: docker or podman is required" >&2
    exit 127
fi

reuse_hint() {
    echo "Tip: reuse this image on later runs with:"
    echo "  STASHD_SMOKE_SKIP_BUILD=1 tests/docker/smoke.sh"
    echo "or:"
    echo "  STASHD_SMOKE_SKIP_BUILD=1 composer test:docker-smoke"
}

media_host_path() {
    case "$1" in
        /media/*)
            printf '%s\n' "$TMP/media/${1#/media/}"
            ;;
        *)
            printf '%s\n' "$1"
            ;;
    esac
}

# Stashd runs on PostgreSQL, so schema/state assertions go through psql in the
# PostgreSQL database container rather than a local database file.
db_query() {
    $CONTAINER exec -e PGPASSWORD="$PG_PASSWORD" "$PG_NAME" \
        psql -U "$PG_USER" -d "$PG_DB" -tAc "$1"
}

# Asserts the *application's* schema is present, not merely that PostgreSQL is
# up: a bare "SELECT 1" would pass even if the app never connected or migrated.
assert_schema_present() {
    if ! db_query "SELECT tablename FROM pg_tables WHERE tablename = 'activity_events'" 2>/dev/null | grep -q activity_events; then
        echo "smoke failed: $1" >&2
        exit 1
    fi
}

http_status() {
    curl -s -o /dev/null -w '%{http_code}' "$@"
}

# Extracts a header's value (last match, CRLF-stripped) without relying on
# any particular grep dialect's support for \r in a regex.
header_value() {
    name="$1"
    file="$2"
    tr -d '\r' < "$file" | awk -F': ' -v name="$name" 'tolower($1) == tolower(name) { value = substr($0, length($1) + 3) } END { print value }'
}

cleanup() {
    $CONTAINER rm -f "$NAME" >/dev/null 2>&1 || true
    $CONTAINER rm -f "$PG_NAME" >/dev/null 2>&1 || true
    $CONTAINER network rm "$NETWORK" >/dev/null 2>&1 || true
    rm -f "/tmp/stashd-smoke-cookies-$$"

    if ! rm -rf "$TMP" 2>/dev/null && [ "$CONTAINER" = podman ]; then
        podman unshare rm -rf "$TMP"
    fi
}
trap cleanup EXIT INT TERM

mkdir -p "$TMP/data" "$TMP/media"

if [ "$SKIP_BUILD" != "1" ]; then
    echo "Building ${IMAGE}..."
    if ! $CONTAINER build -t "$IMAGE" "$ROOT"; then
        echo "smoke failed: image build failed for ${IMAGE}" >&2
        echo "After fixing the build, rerun: tests/docker/smoke.sh" >&2
        exit 1
    fi
    echo "Build complete for ${IMAGE}."
    reuse_hint
else
    echo "Skipping image build (STASHD_SMOKE_SKIP_BUILD=1); using ${IMAGE}"
fi

echo "Starting PostgreSQL..."
$CONTAINER network create "$NETWORK" >/dev/null 2>&1 || true
$CONTAINER run -d --name "$PG_NAME" --network "$NETWORK" --network-alias postgres \
    -e POSTGRES_DB="$PG_DB" \
    -e POSTGRES_USER="$PG_USER" \
    -e POSTGRES_PASSWORD="$PG_PASSWORD" \
    "$PG_IMAGE" >/dev/null

pg_deadline=$(( $(date +%s) + TIMEOUT ))
while :; do
    if $CONTAINER exec "$PG_NAME" pg_isready -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1; then
        break
    fi
    if [ "$(date +%s)" -ge "$pg_deadline" ]; then
        echo "smoke failed: PostgreSQL not ready within ${TIMEOUT}s" >&2
        $CONTAINER logs "$PG_NAME" 2>&1 || true
        exit 1
    fi
    sleep 2
done

echo "Starting container..."
$CONTAINER run -d --name "$NAME" --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data \
    -e STASHD_MEDIA_PATH=/media \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=postgres \
    -e DB_PORT=5432 \
    -e DB_DATABASE="$PG_DB" \
    -e DB_USERNAME="$PG_USER" \
    -e DB_PASSWORD="$PG_PASSWORD" \
    -e PUID="$PUID" \
    -e PGID="$PGID" \
    -v "$TMP/data:/data" \
    -v "$TMP/media:/media" \
    -p 18474:8474 \
    "$IMAGE" >/dev/null

wait_for_health() {
    deadline=$(( $(date +%s) + TIMEOUT ))

    while [ "$(date +%s)" -lt "$deadline" ]; do
        if ! $CONTAINER ps -q --filter "name=^${NAME}$" | grep -q .; then
            echo "smoke failed: container exited early" >&2
            $CONTAINER logs "$NAME" 2>&1 || true
            exit 1
        fi

        if curl -fsS "http://127.0.0.1:18474/health" >/dev/null 2>&1; then
            return 0
        fi

        sleep 3
    done

    echo "smoke failed: health endpoint not ready within ${TIMEOUT}s" >&2
    $CONTAINER logs "$NAME" 2>&1 || true
    exit 1
}

assert_supervisor_program() {
    program="$1"
    # /health goes green as soon as Caddy binds the port, which can be well
    # before supervisord's startsecs grace period has elapsed for that
    # program (STARTING -> RUNNING is time-gated, not instant) -- poll
    # instead of checking once to avoid racing that transition.
    deadline=$(( $(date +%s) + 15 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        if $CONTAINER exec "$NAME" supervisorctl status "$program" 2>/dev/null | grep -q RUNNING; then
            return 0
        fi
        sleep 1
    done

    echo "smoke failed: supervisord program not running: ${program}" >&2
    $CONTAINER exec "$NAME" supervisorctl status 2>&1 || true
    exit 1
}

wait_for_health

body="$(curl -fsS "http://127.0.0.1:18474/health")"
echo "$body"

case "$body" in
    *'"status":"ok"'*) ;;
    *)
        echo "smoke failed: health status not ok" >&2
        exit 1
        ;;
esac

assert_schema_present "application schema missing after boot (migrations did not run)"

if [ ! -f "$TMP/data/.env" ] || ! grep -q '^SIGNING_KEY=' "$TMP/data/.env"; then
    echo "smoke failed: SIGNING_KEY was not generated/persisted to /data/.env" >&2
    exit 1
fi
signing_key_initial="$(grep '^SIGNING_KEY=' "$TMP/data/.env")"

for dir in vault broadcasts temp cache; do
    if [ ! -d "$TMP/media/$dir" ]; then
        echo "smoke failed: /media/$dir not created" >&2
        exit 1
    fi
done

echo "Checking schema: activity_events present, dropped SSE-transport tables gone..."
if ! db_query "SELECT tablename FROM pg_tables WHERE tablename = 'activity_events'" | grep -q activity_events; then
    echo "smoke failed: activity_events table missing (migrations did not run cleanly)" >&2
    exit 1
fi

# event_notifications/sse_connections were pure SSE-poll transport, dropped by
# DropSseAndEventNotificationTables once Mercure replaced the poll loop.
for dropped_table in event_notifications sse_connections; do
    if db_query "SELECT tablename FROM pg_tables WHERE tablename = '${dropped_table}'" | grep -q "$dropped_table"; then
        echo "smoke failed: ${dropped_table} table still exists (drop migration did not run cleanly)" >&2
        exit 1
    fi
done

echo "Checking supervisord worker lanes + scheduler + frankenphp programs..."
assert_supervisor_program frankenphp
assert_supervisor_program worker-interactive
assert_supervisor_program worker-interactive
assert_supervisor_program worker-background
assert_supervisor_program scheduler

echo "Checking Mercure hub is configured and rejects anonymous subscribers..."
mercure_status="$(http_status "http://127.0.0.1:18474/.well-known/mercure")"
if [ "$mercure_status" != "401" ]; then
    echo "smoke failed: /.well-known/mercure returned ${mercure_status}, expected 401 (anonymous subscribers must be rejected)" >&2
    exit 1
fi

echo "Creating owner account for authenticated API checks..."
setup_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/auth/setup" \
    -H 'Content-Type: application/json' \
    -c /tmp/stashd-smoke-cookies-$$ \
    -b /tmp/stashd-smoke-cookies-$$ \
    -d '{"username":"smoke","password":"smoke-password"}')"
echo "$setup_body"

echo "Logging in to establish session (setup does not itself establish one)..."
login_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/auth/login" \
    -H 'Content-Type: application/json' \
    -c /tmp/stashd-smoke-cookies-$$ \
    -b /tmp/stashd-smoke-cookies-$$ \
    -d '{"username":"smoke","password":"smoke-password"}')"
echo "$login_body"

token="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/auth/tokens" \
    -H 'Content-Type: application/json' \
    -b /tmp/stashd-smoke-cookies-$$ \
    -c /tmp/stashd-smoke-cookies-$$ \
    -d '{"name":"smoke"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')"

if [ -z "$token" ]; then
    echo "smoke failed: could not obtain API token for authenticated checks" >&2
    exit 1
fi

system_health="$(curl -fsS "http://127.0.0.1:18474/api/v1/system/health" \
    -H "Authorization: Bearer ${token}")"
echo "$system_health"

case "$system_health" in
    *'"vault_broadcast_hardlink"'*) ;;
    *)
        echo "smoke failed: /api/v1/system/health missing vault_broadcast_hardlink field" >&2
        exit 1
        ;;
esac

echo "Restarting container to verify data persistence..."
$CONTAINER restart "$NAME" >/dev/null
wait_for_health

body_after_restart="$(curl -fsS "http://127.0.0.1:18474/health")"
echo "$body_after_restart"

case "$body_after_restart" in
    *'"status":"ok"'*) ;;
    *)
        echo "smoke failed: health not ok after restart" >&2
        $CONTAINER logs "$NAME" 2>&1 || true
        exit 1
        ;;
esac

assert_schema_present "application schema missing after restart"

if [ "$(grep '^SIGNING_KEY=' "$TMP/data/.env")" != "$signing_key_initial" ]; then
    echo "smoke failed: SIGNING_KEY changed after container restart" >&2
    exit 1
fi

echo "Recreating container (not just restarting) to verify SIGNING_KEY survives a fresh container..."
$CONTAINER rm -f "$NAME" >/dev/null
$CONTAINER run -d --name "$NAME" --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data \
    -e STASHD_MEDIA_PATH=/media \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=postgres \
    -e DB_PORT=5432 \
    -e DB_DATABASE="$PG_DB" \
    -e DB_USERNAME="$PG_USER" \
    -e DB_PASSWORD="$PG_PASSWORD" \
    -e PUID="$PUID" \
    -e PGID="$PGID" \
    -v "$TMP/data:/data" \
    -v "$TMP/media:/media" \
    -p 18474:8474 \
    "$IMAGE" >/dev/null
wait_for_health

if [ "$(grep '^SIGNING_KEY=' "$TMP/data/.env")" != "$signing_key_initial" ]; then
    echo "smoke failed: SIGNING_KEY changed after container recreate" >&2
    exit 1
fi

recreate_health="$(curl -fsS "http://127.0.0.1:18474/health")"
case "$recreate_health" in
    *'"status":"ok"'*) ;;
    *)
        echo "smoke failed: health not ok after container recreate" >&2
        $CONTAINER logs "$NAME" 2>&1 || true
        exit 1
        ;;
esac

assert_schema_present "application schema missing after container recreate"


echo "Checking Jobs API and disposable preflight..."
jobs_status="$(http_status "http://127.0.0.1:18474/api/v1/jobs" -H "Authorization: Bearer ${token}")"
if [ "$jobs_status" != "200" ]; then
    echo "smoke failed: Jobs API returned ${jobs_status}" >&2
    exit 1
fi

preflight_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/stashes/preflight" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d '{"source_uri":"fake://channel/smoke-e2e","source_title":"Smoke E2E Channel"}')"
case "$preflight_body" in
    *'"preflight"'*) ;;
    *) echo "smoke failed: disposable preflight response missing" >&2; exit 1 ;;
esac

if [ "$(db_query "SELECT tablename FROM pg_tables WHERE tablename = 'commands'")" != "" ]; then
    echo "smoke failed: obsolete commands table still exists" >&2
    exit 1
fi
if ! db_query "SELECT column_name FROM information_schema.columns WHERE table_name = 'jobs' AND column_name = 'stashId'" | grep -q stashId; then
    echo "smoke failed: jobs.stashId missing" >&2
    exit 1
fi

echo "docker smoke test passed (Messenger workers, Jobs API, disposable preflight, and migrated schema)"

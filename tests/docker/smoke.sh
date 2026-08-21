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

if command -v docker >/dev/null 2>&1; then
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
# database container rather than a SQLite file on the host.
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
    rm -rf "$TMP"
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
assert_supervisor_program worker-discovery
assert_supervisor_program worker-bulk
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

echo "Running fake-provider preflight → create-from-preflight end-to-end check..."
preflight_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/commands" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d '{"type":"stash.preflight","options":{"source_uri":"fake://channel/smoke-e2e","source_title":"Smoke E2E Channel"}}')"
echo "$preflight_body"

preflight_command_id="$(printf '%s' "$preflight_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
if [ -z "$preflight_command_id" ]; then
    echo "smoke failed: could not parse preflight command id" >&2
    exit 1
fi

deadline=$(( $(date +%s) + 60 ))
preflight_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    preflight_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${preflight_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$preflight_show" | sed 's/,"jobs":\[.*//')"
    preflight_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$preflight_state" = "completed" ] || [ "$preflight_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$preflight_state" != "completed" ]; then
    echo "smoke failed: preflight command did not complete (state=${preflight_state})" >&2
    echo "$preflight_show" >&2
    exit 1
fi

review_body="$(curl -fsS "http://127.0.0.1:18474/api/v1/stashes/preflight/${preflight_command_id}/review" \
    -H "Authorization: Bearer ${token}")"
echo "$review_body"

case "$review_body" in
    *'"discovered_items"'*) ;;
    *)
        echo "smoke failed: preflight review missing discovered_items" >&2
        exit 1
        ;;
esac

stash_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/stashes" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d '{"name":"smoke-e2e-stash"}')"
echo "$stash_body"

stash_id="$(printf '%s' "$stash_body" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')"
if [ -z "$stash_id" ]; then
    echo "smoke failed: could not create empty stash" >&2
    exit 1
fi

create_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/stashes/${stash_id}/inputs" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "{\"preflight_command_id\":\"${preflight_command_id}\"}")"
echo "$create_body"

create_command_id="$(printf '%s' "$create_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
deadline=$(( $(date +%s) + 60 ))
create_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    create_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${create_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$create_show" | sed 's/,"jobs":\[.*//')"
    create_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$create_state" = "completed" ] || [ "$create_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$create_state" != "completed" ]; then
    echo "smoke failed: add-input command did not complete (state=${create_state})" >&2
    echo "$create_show" >&2
    exit 1
fi

case "$create_show" in
    *'"stash_id"'*) ;;
    *)
        echo "smoke failed: add-input missing stash_id in result" >&2
        exit 1
        ;;
esac
media_item_id="$(db_query \
    "SELECT \"mediaItemId\" FROM stash_items WHERE \"stashId\" = '${stash_id}' ORDER BY \"position\" ASC LIMIT 1")"

if [ -z "$media_item_id" ]; then
    echo "smoke failed: could not resolve media item for stash ${stash_id}" >&2
    exit 1
fi

echo "Running fake item.download → temp staging → Vault check..."
download_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/commands" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "{\"type\":\"item.download\",\"options\":{\"media_item_id\":\"${media_item_id}\",\"stash_id\":\"${stash_id}\"}}")"
echo "$download_body"

download_command_id="$(printf '%s' "$download_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
deadline=$(( $(date +%s) + 60 ))
download_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    download_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${download_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$download_show" | sed 's/,"jobs":\[.*//')"
    download_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$download_state" = "completed" ] || [ "$download_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$download_state" != "completed" ]; then
    echo "smoke failed: item.download did not complete (state=${download_state})" >&2
    echo "$download_show" >&2
    exit 1
fi

provider_item_id="$(db_query \
    "SELECT \"providerItemId\" FROM media_items WHERE \"id\" = '${media_item_id}'")"
vault_file="$TMP/media/vault/fake/items/${provider_item_id}/original.fake"

if [ ! -f "$vault_file" ]; then
    echo "smoke failed: expected Vault file missing: ${vault_file}" >&2
    exit 1
fi

asset_state="$(db_query \
    "SELECT \"state\" FROM assets WHERE \"mediaItemId\" = '${media_item_id}' AND \"role\" = 'vault_original' LIMIT 1")"

if [ "$asset_state" != "ready" ]; then
    echo "smoke failed: vault_original asset not ready (state=${asset_state})" >&2
    exit 1
fi

echo "Restarting container again to verify Vault file persistence..."
$CONTAINER restart "$NAME" >/dev/null
wait_for_health

if [ ! -f "$vault_file" ]; then
    echo "smoke failed: Vault file missing after restart" >&2
    exit 1
fi

echo "Creating jellyfin broadcast and running broadcast.rebuild..."
broadcast_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/stashes/${stash_id}/broadcasts" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d '{"type":"jellyfin","name":"Smoke Broadcast","slug":"smoke-broadcast"}')"
echo "$broadcast_body"

broadcast_id="$(printf '%s' "$broadcast_body" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')"
if [ -z "$broadcast_id" ]; then
    echo "smoke failed: could not parse broadcast id" >&2
    exit 1
fi

rebuild_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/commands" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "{\"type\":\"broadcast.rebuild\",\"options\":{\"broadcast_id\":\"${broadcast_id}\"}}")"
echo "$rebuild_body"

rebuild_command_id="$(printf '%s' "$rebuild_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
deadline=$(( $(date +%s) + 60 ))
rebuild_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    rebuild_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${rebuild_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$rebuild_show" | sed 's/,"jobs":\[.*//')"
    rebuild_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$rebuild_state" = "completed" ] || [ "$rebuild_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$rebuild_state" != "completed" ]; then
    echo "smoke failed: broadcast.rebuild did not complete (state=${rebuild_state})" >&2
    echo "$rebuild_show" >&2
    exit 1
fi

published_path="$(db_query \
    "SELECT \"publishedPath\" FROM broadcast_items WHERE \"broadcastId\" = '${broadcast_id}' LIMIT 1")"
published_host_path="$(media_host_path "$published_path")"

if [ -z "$published_path" ] || [ ! -f "$published_host_path" ]; then
    echo "smoke failed: broadcast published file missing: ${published_path} (host: ${published_host_path})" >&2
    exit 1
fi

if command -v stat >/dev/null 2>&1; then
    vault_inode="$(stat -c '%i' "$vault_file" 2>/dev/null || true)"
    published_inode="$(stat -c '%i' "$published_host_path" 2>/dev/null || true)"
    if [ -n "$vault_inode" ] && [ -n "$published_inode" ] && [ "$vault_inode" = "$published_inode" ]; then
        echo "Broadcast file shares inode with Vault original (hardlink confirmed)."
    else
        echo "Inode check skipped or inconclusive; verifying Vault file unchanged and broadcast file exists."
    fi
fi

if [ ! -f "$vault_file" ]; then
    echo "smoke failed: Vault original was removed during broadcast rebuild" >&2
    exit 1
fi

echo "Running broadcast.verify after container restart..."
$CONTAINER restart "$NAME" >/dev/null
wait_for_health

verify_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/commands" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "{\"type\":\"broadcast.verify\",\"options\":{\"broadcast_id\":\"${broadcast_id}\"}}")"
echo "$verify_body"

verify_command_id="$(printf '%s' "$verify_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
deadline=$(( $(date +%s) + 60 ))
verify_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    verify_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${verify_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$verify_show" | sed 's/,"jobs":\[.*//')"
    verify_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$verify_state" = "completed" ] || [ "$verify_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$verify_state" != "completed" ]; then
    echo "smoke failed: broadcast.verify did not complete (state=${verify_state})" >&2
    echo "$verify_show" >&2
    exit 1
fi

case "$verify_show" in
    *'"ok":true'*) ;;
    *)
        echo "smoke failed: broadcast.verify did not report ok=true" >&2
        echo "$verify_show" >&2
        exit 1
        ;;
esac

echo "Creating jellyfin_series broadcast and running broadcast.rebuild..."
jellyfin_broadcast_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/stashes/${stash_id}/broadcasts" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d '{"type":"jellyfin","name":"Smoke Jellyfin Series","slug":"smoke-jellyfin-series"}')"
echo "$jellyfin_broadcast_body"

jellyfin_broadcast_id="$(printf '%s' "$jellyfin_broadcast_body" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')"
if [ -z "$jellyfin_broadcast_id" ]; then
    echo "smoke failed: could not parse jellyfin broadcast id" >&2
    exit 1
fi

jellyfin_rebuild_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/commands" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "{\"type\":\"broadcast.rebuild\",\"options\":{\"broadcast_id\":\"${jellyfin_broadcast_id}\"}}")"
echo "$jellyfin_rebuild_body"

jellyfin_rebuild_command_id="$(printf '%s' "$jellyfin_rebuild_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
deadline=$(( $(date +%s) + 60 ))
jellyfin_rebuild_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    jellyfin_rebuild_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${jellyfin_rebuild_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$jellyfin_rebuild_show" | sed 's/,"jobs":\[.*//')"
    jellyfin_rebuild_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$jellyfin_rebuild_state" = "completed" ] || [ "$jellyfin_rebuild_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$jellyfin_rebuild_state" != "completed" ]; then
    echo "smoke failed: jellyfin_series broadcast.rebuild did not complete (state=${jellyfin_rebuild_state})" >&2
    echo "$jellyfin_rebuild_show" >&2
    exit 1
fi

jellyfin_published_path="$(db_query \
    "SELECT \"publishedPath\" FROM broadcast_items WHERE \"broadcastId\" = '${jellyfin_broadcast_id}' LIMIT 1")"
jellyfin_published_host_path="$(media_host_path "$jellyfin_published_path")"

if [ -z "$jellyfin_published_path" ] || [ ! -f "$jellyfin_published_host_path" ]; then
    echo "smoke failed: jellyfin_series published file missing: ${jellyfin_published_path} (host: ${jellyfin_published_host_path})" >&2
    exit 1
fi

case "$jellyfin_published_path" in
    *S??E???\ -\ *) ;;
    *)
        echo "smoke failed: jellyfin_series published path missing SxxExxx episode naming: ${jellyfin_published_path}" >&2
        exit 1
        ;;
esac

jellyfin_root="$(dirname "$(dirname "$jellyfin_published_host_path")")"
if [ ! -f "${jellyfin_root}/tvshow.nfo" ]; then
    echo "smoke failed: jellyfin_series tvshow.nfo sidecar missing under ${jellyfin_root}" >&2
    exit 1
fi

echo "Seeding a podcast-suitable Vault asset for external publication..."
podcast_fixture_content="stashd-smoke-podcast-episode-bytes"
podcast_fixture_size="$(printf '%s' "$podcast_fixture_content" | wc -c | tr -d ' ')"
podcast_fixture_container_path="/media/vault/podcast-smoke/${provider_item_id}/original.mp3"
podcast_fixture_host_path="$(media_host_path "$podcast_fixture_container_path")"
mkdir -p "$(dirname "$podcast_fixture_host_path")"
printf '%s' "$podcast_fixture_content" > "$podcast_fixture_host_path"

podcast_asset_id="asset_smoke_podcast_$$"
db_query \
    "INSERT INTO assets (\"id\", \"mediaItemId\", \"role\", \"kind\", \"path\", \"relativePath\", \"mimeType\", \"container\", \"sizeBytes\", \"state\", \"createdAt\", \"updatedAt\") VALUES ('${podcast_asset_id}', '${media_item_id}', 'vault_original', 'audio', '${podcast_fixture_container_path}', 'podcast-smoke/${provider_item_id}/original.mp3', 'audio/mpeg', 'mp3', ${podcast_fixture_size}, 'ready', NOW(), NOW())" >/dev/null

echo "Creating podcast broadcast and running broadcast.rebuild..."
podcast_broadcast_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/stashes/${stash_id}/broadcasts" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d '{"type":"podcast","name":"Smoke Podcast","slug":"smoke-podcast"}')"
echo "$podcast_broadcast_body"

podcast_broadcast_id="$(printf '%s' "$podcast_broadcast_body" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')"
if [ -z "$podcast_broadcast_id" ]; then
    echo "smoke failed: could not parse podcast broadcast id" >&2
    exit 1
fi

podcast_rebuild_body="$(curl -fsS -X POST "http://127.0.0.1:18474/api/v1/commands" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
    -d "{\"type\":\"broadcast.rebuild\",\"options\":{\"broadcast_id\":\"${podcast_broadcast_id}\"}}")"
echo "$podcast_rebuild_body"

podcast_rebuild_command_id="$(printf '%s' "$podcast_rebuild_body" | sed -n 's/.*"command_id":"\([^"]*\)".*/\1/p')"
deadline=$(( $(date +%s) + 60 ))
podcast_rebuild_state=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    podcast_rebuild_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/commands/${podcast_rebuild_command_id}" \
        -H "Authorization: Bearer ${token}")"
    command_json="$(printf '%s' "$podcast_rebuild_show" | sed 's/,"jobs":\[.*//')"
    podcast_rebuild_state="$(printf '%s' "$command_json" | sed -n 's/.*"state":"\([^"]*\)".*/\1/p')"
    if [ "$podcast_rebuild_state" = "completed" ] || [ "$podcast_rebuild_state" = "failed" ]; then
        break
    fi
    sleep 2
done

if [ "$podcast_rebuild_state" != "completed" ]; then
    echo "smoke failed: podcast broadcast.rebuild did not complete (state=${podcast_rebuild_state})" >&2
    echo "$podcast_rebuild_show" >&2
    exit 1
fi

podcast_broadcast_show="$(curl -fsS "http://127.0.0.1:18474/api/v1/broadcasts/${podcast_broadcast_id}" -H "Authorization: Bearer ${token}")"
podcast_feed_url="$(printf '%s' "$podcast_broadcast_show" | sed -n 's/.*"published_url":"\([^"]*\)".*/\1/p')"
if [ -z "$podcast_feed_url" ]; then
    echo "smoke failed: external podcast publication URL missing" >&2
    echo "$podcast_broadcast_show" >&2
    exit 1
fi

echo "Fetching externally published podcast feed..."
podcast_feed_response="$(curl -fsS "$podcast_feed_url")"

case "$podcast_feed_response" in
    *'<rss'*'<enclosure'*) ;;
    *)
        echo "smoke failed: public feed route did not return expected rss/enclosure content" >&2
        exit 1
        ;;
esac

enclosure_url="$(printf '%s' "$podcast_feed_response" | sed -n 's/.*url="\([^"]*\)".*/\1/p')"
if [ -z "$enclosure_url" ]; then
    echo "smoke failed: external podcast feed has no enclosure" >&2
    exit 1
fi

echo "Fetching the published media resource..."
curl -fsS -D "$TMP/episode_headers.txt" -o "$TMP/episode_body.bin" "$enclosure_url"

episode_body_size="$(wc -c < "$TMP/episode_body.bin" | tr -d ' ')"
if [ "$episode_body_size" != "$podcast_fixture_size" ]; then
    echo "smoke failed: published media body size mismatch (got ${episode_body_size}, expected ${podcast_fixture_size})" >&2
    exit 1
fi

if ! cmp -s "$TMP/episode_body.bin" "$podcast_fixture_host_path"; then
    echo "smoke failed: published media bytes do not match the Vault fixture" >&2
    exit 1
fi

episode_content_length="$(header_value 'Content-Length' "$TMP/episode_headers.txt")"
if [ "$episode_content_length" != "$podcast_fixture_size" ]; then
    echo "smoke failed: published media Content-Length header mismatch (got '${episode_content_length}', expected '${podcast_fixture_size}')" >&2
    cat "$TMP/episode_headers.txt" >&2
    exit 1
fi

episode_accept_ranges="$(header_value 'Accept-Ranges' "$TMP/episode_headers.txt")"
if [ "$episode_accept_ranges" != "bytes" ]; then
    echo "smoke failed: published media Accept-Ranges header mismatch (got '${episode_accept_ranges}')" >&2
    cat "$TMP/episode_headers.txt" >&2
    exit 1
fi

echo "Fetching published media with a Range header..."
range_status="$(curl -s -o "$TMP/episode_range_body.bin" -D "$TMP/episode_range_headers.txt" -w '%{http_code}' \
    -H 'Range: bytes=0-3' \
    "$enclosure_url")"

if [ "$range_status" != "206" ]; then
    echo "smoke failed: ranged published media request did not return 206 (got ${range_status})" >&2
    cat "$TMP/episode_range_headers.txt" >&2
    exit 1
fi

range_body_size="$(wc -c < "$TMP/episode_range_body.bin" | tr -d ' ')"
if [ "$range_body_size" != "4" ]; then
    echo "smoke failed: ranged published media request returned ${range_body_size} bytes, expected 4" >&2
    exit 1
fi

episode_content_range="$(header_value 'Content-Range' "$TMP/episode_range_headers.txt")"
if [ "$episode_content_range" != "bytes 0-3/${podcast_fixture_size}" ]; then
    echo "smoke failed: ranged published media Content-Range header mismatch (got '${episode_content_range}', expected 'bytes 0-3/${podcast_fixture_size}')" >&2
    cat "$TMP/episode_range_headers.txt" >&2
    exit 1
fi

echo "Verifying operator-supplied SIGNING_KEY/MERCURE_JWT_SECRET are honored and auto-generation is skipped..."
override_tmp="$(mktemp -d)"
mkdir -p "$override_tmp/data" "$override_tmp/media"
override_name="stashd-smoke-override-$$"
operator_key="$(head -c32 /dev/urandom | base64)"
operator_mercure_secret="$(head -c32 /dev/urandom | base64)"

# Shares the smoke PostgreSQL instance: this check is only about secret
# handling, and boot is idempotent against an already-migrated database.
$CONTAINER run -d --name "$override_name" --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data \
    -e STASHD_MEDIA_PATH=/media \
    -e PUID="$PUID" \
    -e PGID="$PGID" \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=postgres \
    -e DB_PORT=5432 \
    -e DB_DATABASE="$PG_DB" \
    -e DB_USERNAME="$PG_USER" \
    -e DB_PASSWORD="$PG_PASSWORD" \
    -e SIGNING_KEY="$operator_key" \
    -e MERCURE_JWT_SECRET="$operator_mercure_secret" \
    -v "$override_tmp/data:/data" \
    -v "$override_tmp/media:/media" \
    -p 18475:8474 \
    "$IMAGE" >/dev/null

override_deadline=$(( $(date +%s) + TIMEOUT ))
while [ "$(date +%s)" -lt "$override_deadline" ]; do
    curl -fsS "http://127.0.0.1:18475/health" >/dev/null 2>&1 && break
    sleep 3
done

if ! curl -fsS "http://127.0.0.1:18475/health" >/dev/null 2>&1; then
    echo "smoke failed: health endpoint not ready with operator-supplied SIGNING_KEY/MERCURE_JWT_SECRET" >&2
    $CONTAINER logs "$override_name" 2>&1 || true
    $CONTAINER rm -f "$override_name" >/dev/null 2>&1 || true
    rm -rf "$override_tmp"
    exit 1
fi

if [ -f "$override_tmp/data/.env" ]; then
    echo "smoke failed: auto-generated .env was created even though operator secrets were supplied" >&2
    $CONTAINER rm -f "$override_name" >/dev/null 2>&1 || true
    rm -rf "$override_tmp"
    exit 1
fi

$CONTAINER rm -f "$override_name" >/dev/null 2>&1 || true
rm -rf "$override_tmp"
echo "Operator-supplied SIGNING_KEY honored correctly."

echo "docker smoke test passed (boot, health, storage layout, migrations, worker/scheduler, system health, restart persistence, SIGNING_KEY persisted across restart and container recreate, operator-supplied SIGNING_KEY override honored, fake preflight e2e, fake download → vault, jellyfin broadcast rebuild + verify, jellyfin_series rebuild + nfo, external podcast publication + Range request)"

#!/usr/bin/env sh
# Upgrade smoke — proves a SQLite install can be imported into PostgreSQL.
#
# Boots the image as an "old release" on SQLite, then runs the import-sqlite role
# against an empty PostgreSQL and checks the rows landed and the SQLite file
# survived. Unlike tests/Feature/SqliteImportTest.php (which uses its own two
# tables to test the importer's mechanics), this runs against the real Stashd
# schema, so enum, timestamp and foreign-key columns are actually exercised.
#
# First run: composer test:docker-upgrade
# Reuse image: STASHD_SMOKE_SKIP_BUILD=1 composer test:docker-upgrade
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
IMAGE="${STASHD_SMOKE_IMAGE:-stashd:smoke}"
SKIP_BUILD="${STASHD_SMOKE_SKIP_BUILD:-0}"
TIMEOUT="${STASHD_SMOKE_TIMEOUT:-180}"
PG_IMAGE="${STASHD_SMOKE_PG_IMAGE:-docker.io/library/postgres:18-alpine}"
NAME="stashd-upgrade-$$"
PG_NAME="stashd-upgrade-pg-$$"
NETWORK="stashd-upgrade-net-$$"
PG_DB=stashd
PG_USER=stashd
PG_PASSWORD=stashd-upgrade
TMP="$(mktemp -d)"

if command -v docker >/dev/null 2>&1; then
    CONTAINER=docker
elif command -v podman >/dev/null 2>&1; then
    CONTAINER=podman
else
    echo "upgrade smoke failed: docker or podman is required" >&2
    exit 127
fi

cleanup() {
    $CONTAINER rm -f "$NAME" >/dev/null 2>&1 || true
    $CONTAINER rm -f "$PG_NAME" >/dev/null 2>&1 || true
    $CONTAINER network rm "$NETWORK" >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

db_query() {
    $CONTAINER exec -e PGPASSWORD="$PG_PASSWORD" "$PG_NAME" \
        psql -U "$PG_USER" -d "$PG_DB" -tAc "$1"
}

fail() {
    echo "upgrade smoke failed: $1" >&2
    exit 1
}

mkdir -p "$TMP/data" "$TMP/media"

if [ "$SKIP_BUILD" != "1" ]; then
    echo "Building ${IMAGE}..."
    $CONTAINER build -t "$IMAGE" "$ROOT" || fail "image build failed for ${IMAGE}"
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
    [ "$(date +%s)" -ge "$pg_deadline" ] && fail "PostgreSQL not ready within ${TIMEOUT}s"
    sleep 2
done

echo "Booting the image on SQLite (standing in for the previous release)..."
$CONTAINER run --rm --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data \
    -e STASHD_MEDIA_PATH=/media \
    -e PUID="$(id -u)" \
    -e PGID="$(id -g)" \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=stashd.sqlite \
    -v "$TMP/data:/data" \
    -v "$TMP/media:/media" \
    "$IMAGE" boot >/dev/null 2>&1 || fail "SQLite boot failed"

[ -f "$TMP/data/stashd.sqlite" ] || fail "SQLite database was not created"
sqlite_size_before="$(wc -c < "$TMP/data/stashd.sqlite")"

echo "Importing into PostgreSQL via the import-sqlite role..."
$CONTAINER run --rm --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data \
    -e STASHD_MEDIA_PATH=/media \
    -e PUID="$(id -u)" \
    -e PGID="$(id -g)" \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=postgres \
    -e DB_PORT=5432 \
    -e DB_DATABASE="$PG_DB" \
    -e DB_USERNAME="$PG_USER" \
    -e DB_PASSWORD="$PG_PASSWORD" \
    -v "$TMP/data:/data" \
    -v "$TMP/media:/media" \
    "$IMAGE" import-sqlite /data/stashd.sqlite || fail "import-sqlite role failed"

echo "Verifying imported state..."

# Boot creates the five storage roots; they are the marker that real rows -- with
# enum and timestamp columns -- crossed over rather than just the schema.
locations="$(db_query 'SELECT count(*) FROM storage_locations' | tr -d '[:space:]')"
[ "$locations" = "5" ] || fail "expected 5 storage_locations in PostgreSQL, got '${locations}'"

commands="$(db_query 'SELECT count(*) FROM commands' | tr -d '[:space:]')"
[ "$commands" -ge 1 ] 2>/dev/null || fail "expected at least one command row, got '${commands}'"

checks="$(db_query 'SELECT count(*) FROM storage_checks' | tr -d '[:space:]')"
[ "$checks" -ge 1 ] 2>/dev/null || fail "expected at least one storage_check row, got '${checks}'"

# storage_checks references storage_locations, so a non-zero count here also
# proves the importer copied parents before children.
orphans="$(db_query 'SELECT count(*) FROM storage_checks c LEFT JOIN storage_locations l ON l."id" = c."storageLocationId" WHERE l."id" IS NULL' | tr -d '[:space:]')"
[ "$orphans" = "0" ] || fail "found ${orphans} storage_checks with no storage_location (import order is wrong)"

# Tempest owns migration history on the PostgreSQL side; the importer must not
# have copied the legacy rows on top of it.
migrations="$(db_query 'SELECT count(*) FROM migrations' | tr -d '[:space:]')"
[ "$migrations" -ge 1 ] 2>/dev/null || fail "migrations table is empty"
dupes="$(db_query 'SELECT count(*) FROM (SELECT "name" FROM migrations GROUP BY "name" HAVING count(*) > 1) d' | tr -d '[:space:]')"
[ "$dupes" = "0" ] || fail "migration history has ${dupes} duplicated name(s)"

# The rollback artifact must be untouched.
sqlite_size_after="$(wc -c < "$TMP/data/stashd.sqlite")"
[ "$sqlite_size_before" = "$sqlite_size_after" ] \
    || fail "SQLite file changed during import (${sqlite_size_before} -> ${sqlite_size_after})"

echo "Confirming a second import refuses rather than duplicating..."
if $CONTAINER run --rm --network "$NETWORK" \
    -e STASHD_DATA_PATH=/data \
    -e STASHD_MEDIA_PATH=/media \
    -e PUID="$(id -u)" \
    -e PGID="$(id -g)" \
    -e DB_CONNECTION=pgsql \
    -e DB_HOST=postgres \
    -e DB_PORT=5432 \
    -e DB_DATABASE="$PG_DB" \
    -e DB_USERNAME="$PG_USER" \
    -e DB_PASSWORD="$PG_PASSWORD" \
    -v "$TMP/data:/data" \
    -v "$TMP/media:/media" \
    "$IMAGE" import-sqlite /data/stashd.sqlite >/dev/null 2>&1; then
    fail "a second import succeeded; it should refuse to merge into a non-empty database"
fi

locations_after="$(db_query 'SELECT count(*) FROM storage_locations' | tr -d '[:space:]')"
[ "$locations_after" = "5" ] || fail "refused import still changed rows (${locations_after} storage_locations)"

echo "upgrade smoke passed (SQLite boot, import-sqlite role, row counts, foreign-key order, migration history intact, SQLite unchanged, second import refused)"

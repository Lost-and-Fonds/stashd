#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-playback-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_PLAYBACK_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_PLAYBACK_PORT:-18475}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
export SIGNING_KEY="${STASHD_PLAYBACK_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"

curl() {
    command curl --connect-timeout 1 --max-time "${STASHD_PLAYBACK_CURL_TIMEOUT:-15}" "$@"
}

TMP=$(mktemp -d)
FIXTURE='stashd-public-playback-fixture-0123456789'
WRONG_FIXTURE='this-is-the-wrong-internal-target'

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        docker compose -f "$ROOT/docker-compose.yml" logs stashd >&2 2>/dev/null || true
    fi
    docker compose -f "$ROOT/docker-compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
    docker run --rm -v "$TMP:/cleanup" python:3.12-slim sh -c 'rm -rf /cleanup/*' >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
    rmdir "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

export STASHD_DATA_DIR="$TMP/data"
export STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$TMP/media/vault"
printf '%s' "$FIXTURE" > "$TMP/media/vault/playback-boundary.mp4"
printf '%s' "$WRONG_FIXTURE" > "$TMP/media/vault/playback-boundary-wrong.bin"

if [ "${STASHD_PLAYBACK_SKIP_BUILD:-0}" != "1" ]; then
    docker build -t "$STASHD_IMAGE" "$ROOT"
fi
docker compose -f "$ROOT/docker-compose.yml" up -d

stashd_container=$(docker compose -f "$ROOT/docker-compose.yml" ps -q stashd)
boot_log=''
health_status=''
for _ in $(seq 1 180); do
    boot_log=$(timeout 5s docker logs --since 15m "$stashd_container" 2>/dev/null || true)
    health_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' \
        "$stashd_container" 2>/dev/null || true)
    if printf '%s' "$boot_log" | grep -q 'Stashd boot completed.' || [ "$health_status" = healthy ]; then
        break
    fi
    sleep 2
done
printf '%s' "$boot_log" | grep -q 'Stashd boot completed.' || [ "$health_status" = healthy ]

base="$STASHD_PUBLIC_URL"
cookie_jar="$TMP/cookies"
health_body="$TMP/health-body"
health_code=''
for _ in $(seq 1 180); do
    health_code=$(curl -sS -o "$health_body" -w '%{http_code}' "$base/health" || true)
    [ "$health_code" = 200 ] && break
    sleep 2
done
[ "$health_code" = 200 ] || { cat "$health_body" >&2 2>/dev/null || true; exit 1; }

curl -fsS -X POST "$base/api/v1/auth/setup" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"playback","password":"playback-password"}' >/dev/null
curl -fsS -X POST "$base/api/v1/auth/login" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"playback","password":"playback-password"}' >/dev/null
token=$(curl -fsS -X POST "$base/api/v1/auth/tokens" \
    -H 'Content-Type: application/json' -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"name":"playback-boundary"}' | jq -r '.token // empty')
[ -n "$token" ] || { echo 'playback proof failed: no API token' >&2; exit 1; }

docker compose -f "$ROOT/docker-compose.yml" cp \
    "$ROOT/tests/docker/playback-boundary.php" stashd:/tmp/playback-boundary.php
fixture=$(docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/playback-boundary.php)
item_id=$(printf '%s' "$fixture" | jq -r '.item_id')
asset_id=$(printf '%s' "$fixture" | jq -r '.asset_id')
fixture_size=$(printf '%s' "$fixture" | jq -r '.size')
fixture_checksum=$(printf '%s' "$fixture" | jq -r '.checksum')
playback_url="$base/api/v1/items/$item_id/playback"

unauth_headers="$TMP/unauth.headers"
unauth_body="$TMP/unauth.body"
unauth_code=$(curl -sS -D "$unauth_headers" -o "$unauth_body" -w '%{http_code}' "$playback_url")
[ "$unauth_code" = 401 ] || { echo "playback proof failed: unauthenticated HTTP $unauth_code" >&2; exit 1; }

full_headers="$TMP/full.headers"
full_body="$TMP/full.body"
full_code=$(curl -sS -D "$full_headers" -o "$full_body" -w '%{http_code}' \
    -H "Authorization: Bearer $token" "$playback_url")
[ "$full_code" = 200 ] || { echo "playback proof failed: full HTTP $full_code" >&2; exit 1; }
grep -Eiq '^Content-Type: video/mp4' "$full_headers"
awk -v expected="$fixture_size" 'tolower($1) == "content-length:" && ($2 + 0) == expected { found = 1 } END { exit ! found }' "$full_headers"
cmp -s "$full_body" "$TMP/media/vault/playback-boundary.mp4"
[ "$(sha256sum "$full_body" | awk '{print $1}')" = "$fixture_checksum" ]

range_headers="$TMP/range.headers"
range_body="$TMP/range.body"
range_code=$(curl -sS -D "$range_headers" -o "$range_body" -w '%{http_code}' \
    -H "Authorization: Bearer $token" -H 'Range: bytes=0-4' "$playback_url")
[ "$range_code" = 206 ] || { echo "playback proof failed: range HTTP $range_code" >&2; exit 1; }
grep -Eiq "^Content-Range: bytes 0-4/$fixture_size" "$range_headers"
printf '%s' "${FIXTURE:0:5}" | cmp -s - "$range_body"

docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/playback-boundary.php set-path "$asset_id" /media/vault/playback-boundary-wrong.bin
sensitivity_headers="$TMP/sensitivity.headers"
sensitivity_body="$TMP/sensitivity.body"
sensitivity_code=$(curl -sS -D "$sensitivity_headers" -o "$sensitivity_body" -w '%{http_code}' \
    -H "Authorization: Bearer $token" "$playback_url")
[ "$sensitivity_code" = 200 ] || { echo "playback sensitivity failed: mutated target HTTP $sensitivity_code" >&2; exit 1; }
if cmp -s "$sensitivity_body" "$TMP/media/vault/playback-boundary.mp4"; then
    echo 'playback sensitivity failed: wrong internal target still returned the fixture' >&2
    exit 1
fi
docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/playback-boundary.php set-path "$asset_id" /media/vault/playback-boundary.mp4

echo "production playback boundary passed: item=$item_id size=$fixture_size sha256=$fixture_checksum range=bytes 0-4/$fixture_size sensitivity=detected"

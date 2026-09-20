#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-published-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_PUBLISHED_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_PUBLISHED_PORT:-18478}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
export SIGNING_KEY="${STASHD_PUBLISHED_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"
export PUID="${STASHD_PUBLISHED_PUID:-1000}"
export PGID="${STASHD_PUBLISHED_PGID:-1000}"

curl_request() {
    command curl --connect-timeout 1 --max-time "${STASHD_PUBLISHED_CURL_TIMEOUT:-15}" "$@"
}

TMP=$(mktemp -d)
fixture_json="$TMP/fixture.json"
broadcast_json="$TMP/broadcast.json"
job_json="$TMP/job.json"
broadcast_state_json="$TMP/broadcast-state.json"
publication_json="$TMP/publication.json"
public_headers="$TMP/public.headers"
public_body="$TMP/public.body"
missing_headers="$TMP/missing.headers"
missing_body="$TMP/missing.body"
unknown_headers="$TMP/unknown.headers"
unknown_body="$TMP/unknown.body"
restored_headers="$TMP/restored.headers"
restored_body="$TMP/restored.body"
generated_path=''
moved_path=''
moved=0

cleanup() {
    status=$?
    if [ "$moved" -eq 1 ] && [ -n "$generated_path" ] && [ -n "$moved_path" ]; then
        docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
            mv "$moved_path" "$generated_path" >/dev/null 2>&1 || true
    fi

    if [ "$status" -ne 0 ]; then
        echo '--- published fixture ---' >&2
        cat "$fixture_json" >&2 2>/dev/null || true
        echo '--- broadcast creation ---' >&2
        cat "$broadcast_json" >&2 2>/dev/null || true
        echo '--- broadcast job ---' >&2
        cat "$job_json" >&2 2>/dev/null || true
        echo '--- broadcast state ---' >&2
        cat "$broadcast_state_json" >&2 2>/dev/null || true
        echo '--- publication registration ---' >&2
        cat "$publication_json" >&2 2>/dev/null || true
        for file in "$public_headers" "$public_body" "$missing_headers" "$missing_body" "$unknown_headers" "$unknown_body" "$restored_headers" "$restored_body"; do
            [ -f "$file" ] && { echo "--- $file ---" >&2; head -c 4000 "$file" >&2 || true; echo >&2; }
        done
        echo '--- Stashd logs ---' >&2
        docker compose -f "$ROOT/docker-compose.yml" logs stashd >&2 2>/dev/null || true
        echo '--- Messenger queue diagnostics ---' >&2
        docker compose -f "$ROOT/docker-compose.yml" exec -T postgres \
            psql -U "${POSTGRES_USER:-stashd}" -d "${POSTGRES_DB:-stashd}" -P pager=off \
            -c 'SELECT queue_name, delivered_at, available_at, left(headers, 2000) AS headers, left(body, 6000) AS body FROM messenger_messages ORDER BY id DESC LIMIT 10;' \
            >&2 2>/dev/null || true
        if [ -n "$broadcast_id" ]; then
            docker compose -f "$ROOT/docker-compose.yml" exec -T postgres \
                psql -U "${POSTGRES_USER:-stashd}" -d "${POSTGRES_DB:-stashd}" -P pager=off \
                -c "SELECT id, state, \"publishedPath\", \"lastError\" FROM broadcast_items WHERE \"broadcastId\" = '$broadcast_id';" \
                >&2 2>/dev/null || true
        fi
    fi

    docker compose -f "$ROOT/docker-compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

export STASHD_DATA_DIR="$TMP/data"
export STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"

if [ "${STASHD_PUBLISHED_SKIP_BUILD:-0}" != "1" ]; then
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
health_body="$TMP/health.body"
health_code=''
for _ in $(seq 1 180); do
    health_code=$(curl_request -sS -o "$health_body" -w '%{http_code}' "$base/health" || true)
    [ "$health_code" = 200 ] && break
    sleep 2
done
[ "$health_code" = 200 ] || { cat "$health_body" >&2 2>/dev/null || true; exit 1; }

curl_request -fsS -X POST "$base/api/v1/auth/setup" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"published","password":"published-password"}' >/dev/null
curl_request -fsS -X POST "$base/api/v1/auth/login" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"published","password":"published-password"}' >/dev/null
token=$(curl_request -fsS -X POST "$base/api/v1/auth/tokens" \
    -H 'Content-Type: application/json' -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"name":"published-boundary"}' | jq -r '.token // empty')
[ -n "$token" ] || { echo 'Published proof failed: no API token' >&2; exit 1; }

docker compose -f "$ROOT/docker-compose.yml" cp \
    "$ROOT/tests/docker/published-boundary.php" stashd:/tmp/published-boundary.php

timeout 30s docker compose -f "$ROOT/docker-compose.yml" exec -T --user "$PUID:$PGID" stashd \
    php /tmp/published-boundary.php seed >"$fixture_json"
stash_id=$(jq -r '.stash_id' "$fixture_json")
fixture_size=$(jq -r '.fixture_size' "$fixture_json")
fixture_checksum=$(jq -r '.fixture_checksum' "$fixture_json")
vault_path=$(jq -r '.vault_path' "$fixture_json")
jq -e --arg size "$fixture_size" '
    .stash_id != null and .item_id != null and .asset_id != null and
    .storage.state == "ready" and .storage.readable == true and .storage.writable == true and
    (.fixture_size | tostring) == $size
' "$fixture_json" >/dev/null

broadcast_code=$(curl_request -sS -o "$broadcast_json" -w '%{http_code}' \
    -X POST "$base/api/v1/stashes/$stash_id/broadcasts" \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $token" \
    -d '{"type":"filesystem","name":"Published resource boundary"}')
[ "$broadcast_code" = 201 ] || {
    echo "Published proof failed: filesystem broadcast creation returned HTTP $broadcast_code" >&2
    exit 1
}
broadcast_id=$(jq -r '.broadcast.id // empty' "$broadcast_json")
build_job_id=$(jq -r '.build_job_id // empty' "$broadcast_json")
[ -n "$broadcast_id" ] && [ -n "$build_job_id" ]
jq -e '.broadcast.type == "filesystem" and .broadcast.state == "pending"' "$broadcast_json" >/dev/null

job_terminal=''
for _ in $(seq 1 "${STASHD_PUBLISHED_JOB_TIMEOUT:-120}"); do
    curl_request -fsS -H "Authorization: Bearer $token" \
        "$base/api/v1/jobs/$build_job_id" >"$job_json"
    job_terminal=$(jq -r '.job.state // empty' "$job_json")
    if [ "$(jq -r '.job.last_error // empty' "$job_json")" != "" ]; then
        jq -c '{state: .job.state, attempts: .job.attempts, last_error: .job.last_error}' "$job_json" >&2
    fi
    case "$job_terminal" in
        ready|failed|cancelled) break ;;
    esac
    sleep 1
done
jq -e --arg bid "$broadcast_id" '
    .job.type == "core.broadcast" and .job.entity_type == "broadcast" and
    .job.entity_id == $bid and .job.state == "ready" and
    (.job.attempts // 0) >= 1 and .job.started_at != null and
    .job.finished_at != null and .job.progress_percent == 100
' "$job_json" >/dev/null

curl_request -fsS -H "Authorization: Bearer $token" \
    "$base/api/v1/broadcasts/$broadcast_id" >"$broadcast_state_json"
jq -e '.broadcast.id != null and .broadcast.type == "filesystem" and .broadcast.state == "ready"' \
    "$broadcast_state_json" >/dev/null

timeout 30s docker compose -f "$ROOT/docker-compose.yml" exec -T --user "$PUID:$PGID" stashd \
    php /tmp/published-boundary.php register "$broadcast_id" >"$publication_json"
publication_id=$(jq -r '.publication_id' "$publication_json")
published_url=$(jq -r '.publication_url' "$publication_json")
generated_path=$(jq -r '.generated_path' "$publication_json")
generated_size=$(jq -r '.generated_size' "$publication_json")
generated_checksum=$(jq -r '.generated_checksum' "$publication_json")
[ "$generated_size" = "$fixture_size" ]
[ "$generated_checksum" = "$fixture_checksum" ]
[ "$(jq -r '.publication_state' "$publication_json")" = ready ]
[ "$(jq -r '.publication_access' "$publication_json")" = public ]
[ "$(jq -r '.publication_media_type' "$publication_json")" = video/mp4 ]
[ "$(jq -r '.publication_download_name' "$publication_json")" = published-boundary.mp4 ]
printf '%s' "$published_url" | grep -Fq "/published/$publication_id"

docker compose -f "$ROOT/docker-compose.yml" exec -T --user "$PUID:$PGID" stashd \
    cmp "$generated_path" "$vault_path"
docker compose -f "$ROOT/docker-compose.yml" exec -T --user "$PUID:$PGID" stashd \
    test -f "$generated_path"

public_code=$(curl_request -sS -D "$public_headers" -o "$public_body" -w '%{http_code}' "$published_url")
[ "$public_code" = 200 ] || { echo "Published proof failed: public resource returned HTTP $public_code" >&2; exit 1; }
grep -Eiq '^Content-Type: video/mp4' "$public_headers"
awk -v expected="$fixture_size" 'tolower($1) == "content-length:" && ($2 + 0) == expected { found = 1 } END { exit ! found }' "$public_headers"
grep -Eiq '^Accept-Ranges: bytes' "$public_headers"
grep -Eiq '^Content-Disposition: inline; filename="published-boundary\.mp4"' "$public_headers"
cmp -s "$public_body" "$TMP/media/vault/published-boundary.mp4"
[ "sha256:$(sha256sum "$public_body" | awk '{print $1}')" = "$fixture_checksum" ]

unknown_id="${publication_id%?}0"
[ "$unknown_id" = "$publication_id" ] && unknown_id="${publication_id%?}1"
unknown_code=$(curl_request -sS -D "$unknown_headers" -o "$unknown_body" -w '%{http_code}' \
    "$base/published/$unknown_id")
[ "$unknown_code" = 404 ] || { echo "Published proof failed: unknown publication returned HTTP $unknown_code" >&2; exit 1; }

moved_path="${generated_path}.moved"
docker compose -f "$ROOT/docker-compose.yml" exec -T --user "$PUID:$PGID" stashd \
    mv "$generated_path" "$moved_path"
moved=1
missing_code=$(curl_request -sS -D "$missing_headers" -o "$missing_body" -w '%{http_code}' "$published_url")
[ "$missing_code" = 404 ] || { echo "Published sensitivity failed: missing file returned HTTP $missing_code" >&2; exit 1; }
if [ -s "$missing_body" ] && cmp -s "$missing_body" "$TMP/media/vault/published-boundary.mp4"; then
    echo 'Published sensitivity failed: missing generated file returned the fixture bytes' >&2
    exit 1
fi

docker compose -f "$ROOT/docker-compose.yml" exec -T --user "$PUID:$PGID" stashd \
    mv "$moved_path" "$generated_path"
moved=0
restored_code=$(curl_request -sS -D "$restored_headers" -o "$restored_body" -w '%{http_code}' "$published_url")
[ "$restored_code" = 200 ] || { echo "Published proof failed: restored resource returned HTTP $restored_code" >&2; exit 1; }
cmp -s "$restored_body" "$public_body"

echo "production published-resource boundary passed: broadcast=$broadcast_id job=$build_job_id publication=$publication_id url=$published_url size=$fixture_size checksum=$fixture_checksum sensitivity=missing-file-404"

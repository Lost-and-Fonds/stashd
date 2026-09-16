#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-golden-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_GOLDEN_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_GOLDEN_PORT:-18474}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
# The smoke deployment restarts after installing plugins. Supply a deterministic
# operator key so this proof is independent of persisted .env generation while
# still exercising the production signing/encryption path.
export SIGNING_KEY="${STASHD_GOLDEN_SIGNING_KEY:-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
# Keep transient deployment connection failures retryable instead of allowing
# one host-side API request to hold the smoke job indefinitely.
curl() {
    command curl --connect-timeout 1 --max-time "${STASHD_GOLDEN_CURL_TIMEOUT:-15}" "$@"
}
TMP=$(mktemp -d)
FIXTURE_CONTAINER="${COMPOSE_PROJECT_NAME}-youtube-fixture"
YOUTUBE_REF="${STASHD_GOLDEN_YOUTUBE_REF:-ghcr.io/lost-and-fonds/youtube@sha256:67600e64ef420711fce8bf041a08b3dc5da308677156c8eb8ff966d66852e29d}"
PODCAST_REF="${STASHD_GOLDEN_PODCAST_REF:-ghcr.io/lost-and-fonds/podcast@sha256:b9660e9285b19215b287d9ac66529bcc0bbc9dc14aa1e0516d3b0cf54bcd2e46}"

cleanup() {
    status=$?
    if [ "$status" -ne 0 ] && [ -n "${FIXTURE_CONTAINER:-}" ]; then
        docker logs "$FIXTURE_CONTAINER" >&2 2>/dev/null || true
    fi
    if [ "$status" -ne 0 ]; then
        docker compose -f "$ROOT/docker-compose.yml" logs stashd >&2 2>/dev/null || true
    fi
    docker rm -f "$FIXTURE_CONTAINER" >/dev/null 2>&1 || true
    docker compose -f "$ROOT/docker-compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
    docker run --rm -v "$TMP:/cleanup" python:3.12-slim sh -c 'rm -rf /cleanup/*' >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
    rmdir "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

export STASHD_DATA_DIR="$TMP/data"
export STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$TMP/fixture"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Stashd golden fixture CA' \
    -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    -keyout "$TMP/fixture/ca-key.pem" -out "$TMP/fixture/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj '/CN=www.youtube.com' \
    -keyout "$TMP/fixture/key.pem" -out "$TMP/fixture/server.csr" >/dev/null 2>&1
openssl x509 -req -days 1 -in "$TMP/fixture/server.csr" -CA "$TMP/fixture/ca.pem" \
    -CAkey "$TMP/fixture/ca-key.pem" -CAcreateserial \
    -extfile <(printf '%s\n' 'basicConstraints=critical,CA:FALSE' 'keyUsage=critical,digitalSignature,keyEncipherment' 'extendedKeyUsage=serverAuth' 'subjectAltName=DNS:www.youtube.com,DNS:youtube.com,DNS:m.youtube.com,DNS:music.youtube.com,DNS:youtu.be,DNS:i.ytimg.com') \
    -out "$TMP/fixture/cert.pem" >/dev/null 2>&1
cp "$ROOT/tests/docker/youtube-fixture-server.py" "$TMP/fixture/server.py"
cp "$ROOT/tests/docker/yt-dlp-fixture.conf" "$TMP/fixture/yt-dlp.conf"

if [ "${STASHD_GOLDEN_SKIP_BUILD:-0}" != "1" ]; then
    docker build -t "$STASHD_IMAGE" "$ROOT"
fi
docker compose -f "$ROOT/docker-compose.yml" up -d

# Compose's --wait treats an early failed health probe as terminal, even when
# the production entrypoint is still booting and will recover. Wait for the
# shipped entrypoint's own boot-complete signal before installing plugins,
# then use the public health endpoint after the required plugin restart below.
stashd_container=$(docker compose -f "$ROOT/docker-compose.yml" ps -q stashd)
boot_log=''
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

network="${COMPOSE_PROJECT_NAME}_default"
docker run -d --name "$FIXTURE_CONTAINER" --network "$network" \
    --network-alias www.youtube.com \
    --network-alias youtube.com \
    --network-alias m.youtube.com \
    --network-alias music.youtube.com \
    --network-alias youtu.be \
    --network-alias i.ytimg.com \
    -v "$TMP/fixture:/fixture:ro" \
    python:3.12-slim python /fixture/server.py >/dev/null

docker compose -f "$ROOT/docker-compose.yml" cp "$TMP/fixture/ca.pem" stashd:/usr/local/share/ca-certificates/stashd-golden.crt
docker compose -f "$ROOT/docker-compose.yml" cp "$TMP/fixture/yt-dlp.conf" stashd:/etc/yt-dlp.conf
timeout 60s docker compose -f "$ROOT/docker-compose.yml" exec -T stashd update-ca-certificates >/dev/null
timeout 60s docker compose -f "$ROOT/docker-compose.yml" exec -T stashd sh -c \
    'cat /usr/local/share/ca-certificates/stashd-golden.crt >> /etc/ssl/certs/ca-certificates.crt'
until timeout 10s docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    curl --connect-timeout 1 --max-time 5 -fsS \
    'https://www.youtube.com/oembed?format=json&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3Dgolden-video' >/dev/null; do
    sleep 1
done
timeout 180s docker compose -f "$ROOT/docker-compose.yml" exec -T stashd php tempest stashd:plugin-install "$YOUTUBE_REF"
timeout 180s docker compose -f "$ROOT/docker-compose.yml" exec -T stashd php tempest stashd:plugin-install "$PODCAST_REF"
# The production image persists its dotenv file under /data and reloads it on
# restart. Keep the smoke deployment's operator key in that authoritative copy
# as well as in Compose's environment.
sed -i '/^SIGNING_KEY=/d' "$STASHD_DATA_DIR/.env" 2>/dev/null || true
printf 'SIGNING_KEY=%s\n' "$SIGNING_KEY" >> "$STASHD_DATA_DIR/.env"
timeout 60s docker compose -f "$ROOT/docker-compose.yml" restart stashd >/dev/null

base="http://127.0.0.1:${STASHD_HOST_PORT}"
cookie_jar="$TMP/cookies"
health_body="$TMP/health-body"
health_code=''
for _ in $(seq 1 180); do
    health_code=$(curl --connect-timeout 1 --max-time 5 -sS -o "$health_body" \
        -w '%{http_code}' "$base/health" || true)
    [ "$health_code" = 200 ] && break
    sleep 2
done
if [ "$health_code" != 200 ]; then
    printf 'golden path failed: health returned HTTP %s\n' "$health_code" >&2
    cat "$health_body" >&2 2>/dev/null || true
    docker compose -f "$ROOT/docker-compose.yml" logs stashd >&2 2>/dev/null || true
    exit 1
fi

curl -fsS -X POST "$base/api/v1/auth/setup" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"golden","password":"golden-password"}' >/dev/null
curl -fsS -X POST "$base/api/v1/auth/login" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"golden","password":"golden-password"}' >/dev/null
token=$(curl -fsS -X POST "$base/api/v1/auth/tokens" \
    -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"name":"golden"}' | jq -r '.token // empty')

if [ -z "$token" ]; then
    echo 'golden path failed: no API token' >&2
    exit 1
fi

stash=$(curl -fsS -X POST "$base/api/v1/stashes/with-input" \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $token" \
    -d '{"name":"Golden Path","input":{"plugin":"youtube","source":{"url":"https://www.youtube.com/watch?v=goldenvid01"}},"downloadPolicy":"video"}')
stash_id=$(printf '%s' "$stash" | jq -r '.stash.id')
job_id=''
for _ in $(seq 1 15); do
    job_id=$(curl -fsS "$base/api/v1/jobs" -H "Authorization: Bearer $token" \
        | jq -r --arg stash_id "$stash_id" '[.jobs[] | select(.type == "core.add_input" and .stash_id == $stash_id)] | .[0].id // empty')
    [ -n "$job_id" ] && break
    sleep 1
done

[ -n "$job_id" ] || { echo 'golden path failed: no input job' >&2; exit 1; }

for _ in $(seq 1 90); do
    items=$(curl -fsS "$base/api/v1/stashes/$stash_id/items" -H "Authorization: Bearer $token")
    state=$(printf '%s' "$items" | jq -r '.items[0].item.state // .items[0].state // empty')
    [ "$state" = ready ] && break
    [ "$state" = failed ] && { echo "$items" >&2; exit 1; }

    job=$(curl -fsS "$base/api/v1/jobs/$job_id" -H "Authorization: Bearer $token")
    job_state=$(printf '%s' "$job" | jq -r '.job.state // empty')
    [ "$job_state" = failed ] && { echo "$job" >&2; exit 1; }
    sleep 2
done

[ "$state" = ready ] || { echo 'golden path failed: item never became ready' >&2; exit 1; }
item_id=$(printf '%s' "$items" | jq -r '.items[0].item_id // .items[0].itemId // .items[0].id')
assets=$(curl -fsS "$base/api/v1/items/$item_id/assets" -H "Authorization: Bearer $token")
printf '%s' "$assets" | jq -e '.assets | any(.[]; .role == "vault_original" and .state == "ready")' >/dev/null

sync=$(curl -fsS -X POST "$base/api/v1/stashes/$stash_id/sync" \
    -H "Authorization: Bearer $token")
sync_job_id=$(printf '%s' "$sync" | jq -r '.job_ids[0] // empty')
[ -n "$sync_job_id" ] || { echo 'golden path failed: sync returned no job id' >&2; exit 1; }

sync_state=''
for _ in $(seq 1 90); do
    sync_job=$(curl -fsS "$base/api/v1/jobs/$sync_job_id" -H "Authorization: Bearer $token")
    sync_state=$(printf '%s' "$sync_job" | jq -r '.job.state // empty')
    [ "$sync_state" = ready ] && break
    [ "$sync_state" = failed ] && { echo "$sync_job" >&2; exit 1; }
    sleep 2
done
[ "$sync_state" = ready ] || { echo "$sync_job" >&2; echo 'golden path failed: sync job never reached terminal ready state' >&2; exit 1; }
synced_items=$(curl -fsS "$base/api/v1/stashes/$stash_id/items" -H "Authorization: Bearer $token")
printf '%s' "$synced_items" | jq -e --arg item_id "$item_id" '.items | any(.[]; (.item_id // .itemId // .id) == $item_id)' >/dev/null

broadcast=$(curl -fsS -X POST "$base/api/v1/stashes/$stash_id/broadcasts" \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $token" \
    -d '{"type":"podcast","name":"Golden Podcast","settings":{"media_kind":"video"}}')
broadcast_id=$(printf '%s' "$broadcast" | jq -r '.broadcast.id')

for _ in $(seq 1 90); do
    current=$(curl -fsS "$base/api/v1/broadcasts/$broadcast_id" -H "Authorization: Bearer $token")
    state=$(printf '%s' "$current" | jq -r '.broadcast.state')
    [ "$state" = ready ] && break
    [ "$state" = failed ] && { echo "$current" >&2; exit 1; }
    sleep 2
done

[ "$state" = ready ] || { echo 'golden path failed: broadcast never became ready' >&2; exit 1; }
published_url=$(printf '%s' "$current" | jq -r '.broadcast.published_url // empty')
[ -n "$published_url" ] || { echo 'golden path failed: no published URL' >&2; exit 1; }
curl -fsS "$published_url" | grep -q 'Golden Path Video'

if [ "${STASHD_GOLDEN_COLLECTION_CHECK:-0}" = "1" ]; then
    filesystem=$(curl -fsS -X POST "$base/api/v1/stashes/$stash_id/broadcasts" \
        -H 'Content-Type: application/json' -H "Authorization: Bearer $token" \
        -d '{"type":"filesystem","name":"Non Podcast Filesystem"}')
    filesystem_id=$(printf '%s' "$filesystem" | jq -r '.broadcast.id')
    filesystem_state=''
    for _ in $(seq 1 90); do
        filesystem_current=$(curl -fsS "$base/api/v1/broadcasts/$filesystem_id" -H "Authorization: Bearer $token")
        filesystem_state=$(printf '%s' "$filesystem_current" | jq -r '.broadcast.state')
        [ "$filesystem_state" = ready ] && break
        [ "$filesystem_state" = failed ] && { echo "$filesystem_current" >&2; exit 1; }
        sleep 2
    done
    [ "$filesystem_state" = ready ] || { echo "$filesystem_current" >&2; exit 1; }

    exporters=$(curl -fsS "$base/api/v1/stash-collection-exporters" -H "Authorization: Bearer $token")
    printf 'podcast exporter discovery: %s\n' "$exporters"
    printf '%s' "$exporters" | jq -e '.exporters | any(.[]; .key == "podcast-opml")' >/dev/null

    export_headers="$TMP/podcast-export.headers"
    export_body="$TMP/podcast-export.opml"
    curl -fsS -D "$export_headers" -o "$export_body" \
        "$base/api/v1/stash-collection-exports/podcast-opml" \
        -H "Authorization: Bearer $token"
    grep -Eiq '^Content-Type: text/(x-opml|xml)(;|$)' "$export_headers"
    grep -Eiq '^Content-Disposition: attachment; filename="stashd-podcasts\.opml"' "$export_headers"
    python3 - "$export_body" "$published_url" <<'PY'
import sys
import xml.etree.ElementTree as ET

root = ET.parse(sys.argv[1]).getroot()
assert root.tag == 'opml'
assert root.attrib.get('version') == '2.0'
outlines = root.findall('./body/outline')
assert len(outlines) == 1
assert outlines[0].attrib.get('xmlUrl') == sys.argv[2]
assert outlines[0].attrib.get('title') == 'Golden Path'
assert 'Non Podcast Filesystem' not in ET.tostring(root, encoding='unicode')
PY
    curl -sS -o /dev/null -w '%{http_code}' \
        "$base/api/v1/stash-collection-exports/not-a-real-exporter" \
        -H "Authorization: Bearer $token" | grep -qx '404'

    echo 'podcast collection export path passed'
fi

echo 'production golden path passed'

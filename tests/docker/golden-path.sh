#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-golden-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_GOLDEN_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_GOLDEN_PORT:-18474}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
COMPOSE_FILES=(-f "$ROOT/docker-compose.yml")
if [ "${STASHD_GOLDEN_APPARMOR:-0}" = "1" ]; then
    COMPOSE_FILES+=(-f "$ROOT/docker-compose.apparmor.yml")
fi
# The smoke deployment restarts after installing plugins. Supply a deterministic
# operator key so this proof is independent of persisted .env generation while
# still exercising the production signing/encryption path.
export SIGNING_KEY="${STASHD_GOLDEN_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"
# Keep transient deployment connection failures retryable instead of allowing
# one host-side API request to hold the smoke job indefinitely.
curl() {
    command curl --connect-timeout 1 --max-time "${STASHD_GOLDEN_CURL_TIMEOUT:-15}" "$@"
}
TMP=$(mktemp -d)
FIXTURE_CONTAINER="${COMPOSE_PROJECT_NAME}-youtube-fixture"
YOUTUBE_REF="${STASHD_GOLDEN_YOUTUBE_REF:-ghcr.io/lost-and-fonds/youtube@sha256:67600e64ef420711fce8bf041a08b3dc5da308677156c8eb8ff966d66852e29d}"
PODCAST_REF="${STASHD_GOLDEN_PODCAST_REF:-ghcr.io/lost-and-fonds/podcast@sha256:b9660e9285b19215b287d9ac66529bcc0bbc9dc14aa1e0516d3b0cf54bcd2e46}"
JELLYFIN_REF="${STASHD_GOLDEN_JELLYFIN_REF:-ghcr.io/lost-and-fonds/jellyfin@sha256:f6d20378365be62669f2936936498272b75431f5a557797c2c4474b27df12617}"
PLEX_REF="${STASHD_GOLDEN_PLEX_REF:-ghcr.io/lost-and-fonds/plex@sha256:bc2c9960978494a7f81060fb28dd90471f60416465e770fc66cd3f8d89fbb1bd}"

cleanup() {
    status=$?
    if [ "$status" -ne 0 ] && [ -n "${FIXTURE_CONTAINER:-}" ]; then
        docker logs "$FIXTURE_CONTAINER" >&2 2>/dev/null || true
    fi
    if [ "$status" -ne 0 ]; then
        docker compose "${COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true
    fi
    docker rm -f "$FIXTURE_CONTAINER" >/dev/null 2>&1 || true
    docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
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
payload_a='stashd-golden-path-media-a'
payload_b='stashd-golden-path-media-b'
retry_payload='stashd-golden-path-retry-media'
caption_payload='WEBVTT

00:00.000 --> 00:01.000
Deterministic fixture caption
'
printf '%s\n' "$payload_a" > "$TMP/fixture/media.bin"
printf '%s\n' "$retry_payload" > "$TMP/fixture/retry-media.bin"
printf 'broken\n' > "$TMP/fixture/retry-mode"
printf 'broken\n' > "$TMP/fixture/caption-mode"
expected_vault_sha256=$(printf '%s\n' "$payload_a" | sha256sum | awk '{print $1}')
refetch_expected_sha256=$(printf '%s\n' "$payload_b" | sha256sum | awk '{print $1}')
refetch_expected_size=$(printf '%s\n' "$payload_b" | wc -c | tr -d ' ')
retry_expected_sha256=$(printf '%s\n' "$retry_payload" | sha256sum | awk '{print $1}')
retry_expected_size=$(printf '%s\n' "$retry_payload" | wc -c | tr -d ' ')
caption_expected_sha256=$(printf '%s' "$caption_payload" | sha256sum | awk '{print $1}')
[ "$expected_vault_sha256" != "$refetch_expected_sha256" ] || {
    echo 'golden path failed: refetch fixture payloads must differ' >&2
    exit 1
}

if [ "${STASHD_GOLDEN_SKIP_BUILD:-0}" != "1" ]; then
    docker build -t "$STASHD_IMAGE" "$ROOT"
fi
docker compose "${COMPOSE_FILES[@]}" up -d

# Compose's --wait treats an early failed health probe as terminal, even when
# the production entrypoint is still booting and will recover. Wait for the
# shipped entrypoint's own boot-complete signal before installing plugins,
# then use the public health endpoint after the required plugin restart below.
stashd_container=$(docker compose "${COMPOSE_FILES[@]}" ps -q stashd)
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

docker compose -f "$ROOT/docker-compose.yml" cp "$ROOT/tests/docker/plugin-sandbox-boundary.php" stashd:/tmp/plugin-sandbox-boundary.php
timeout 60s docker compose -f "$ROOT/docker-compose.yml" exec -T stashd php /tmp/plugin-sandbox-boundary.php

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

docker compose "${COMPOSE_FILES[@]}" cp "$TMP/fixture/ca.pem" stashd:/usr/local/share/ca-certificates/stashd-golden.crt
docker compose "${COMPOSE_FILES[@]}" cp "$TMP/fixture/yt-dlp.conf" stashd:/etc/yt-dlp.conf
timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd update-ca-certificates >/dev/null
timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd sh -c \
    'cat /usr/local/share/ca-certificates/stashd-golden.crt >> /etc/ssl/certs/ca-certificates.crt'
until timeout 10s docker compose "${COMPOSE_FILES[@]}" exec -T stashd \
    curl --connect-timeout 1 --max-time 5 -fsS \
    'https://www.youtube.com/oembed?format=json&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3Dgolden-video' >/dev/null; do
    sleep 1
done
timeout 180s docker compose "${COMPOSE_FILES[@]}" exec -T stashd php tempest stashd:plugin-install "$YOUTUBE_REF"
timeout 180s docker compose "${COMPOSE_FILES[@]}" exec -T stashd php tempest stashd:plugin-install "$PODCAST_REF"
timeout 180s docker compose "${COMPOSE_FILES[@]}" exec -T stashd php tempest stashd:plugin-install "$JELLYFIN_REF"
timeout 180s docker compose "${COMPOSE_FILES[@]}" exec -T stashd php tempest stashd:plugin-install "$PLEX_REF"
# The production image persists its dotenv file under /data and reloads it on
# restart. Keep the smoke deployment's operator key in that authoritative copy
# as well as in Compose's environment.
timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd sh -c \
    "sed -i '/^SIGNING_KEY=/d' /data/.env 2>/dev/null || true; printf 'SIGNING_KEY=%s\\n' \"\$SIGNING_KEY\" >> /data/.env"
timeout 60s docker compose "${COMPOSE_FILES[@]}" restart stashd >/dev/null

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
    docker compose "${COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true
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
    -d '{"name":"Golden Path","input":{"plugin":"youtube","source":{"url":"https://www.youtube.com/watch?v=goldenvid01"},"options":{"provider":{"include_captions":true,"include_auto_captions":false,"caption_languages":"en"}}},"downloadPolicy":"video"}')
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
inputs=$(curl -fsS "$base/api/v1/stashes/$stash_id/inputs" -H "Authorization: Bearer $token")
printf '%s' "$inputs" | jq -e \
    '.inputs | any(.[]; .provider_key == "youtube" and .options.provider.include_captions == true and .options.provider.include_auto_captions == false and .options.provider.caption_languages == "en")' >/dev/null || {
    echo 'golden path failed: explicit YouTube caption options were not persisted' >&2
    printf '%s\n' "$inputs" >&2
    exit 1
}
echo 'golden YouTube caption options persisted: creator captions enabled, auto captions disabled, language=en'
assets=$(curl -fsS "$base/api/v1/items/$item_id/assets" -H "Authorization: Bearer $token")
printf '%s' "$assets" | jq -e '.assets | any(.[]; .role == "vault_original" and .state == "ready")' >/dev/null
printf '%s' "$assets" | jq -e '[.assets[] | select(.role == "vault_original" and .state == "ready")] | length == 1' >/dev/null
vault_asset_path=$(printf '%s' "$assets" | jq -r '.assets[] | select(.role == "vault_original" and .state == "ready") | .path' | head -n 1)
[ -n "$vault_asset_path" ] && [ "$vault_asset_path" != "null" ] || {
    echo 'golden path failed: ready vault_original has no persisted path' >&2
    exit 1
}
actual_vault_sha256=$(timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd \
    sha256sum "$vault_asset_path" | awk '{print $1}')
[ "$actual_vault_sha256" = "$expected_vault_sha256" ] || {
    echo "golden path failed: Vault bytes mismatch for $vault_asset_path" >&2
    echo "expected sha256=$expected_vault_sha256 actual sha256=$actual_vault_sha256" >&2
    exit 1
}
echo "golden Vault bytes verified: path=$vault_asset_path sha256=$actual_vault_sha256"

printf '%s\n' "$payload_b" > "$TMP/fixture/media.bin"
upstream_b_sha256=$(timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd \
    curl --connect-timeout 1 --max-time 5 -fsS \
    'https://www.youtube.com/videoplayback/goldenvid01' | sha256sum | awk '{print $1}')
[ "$upstream_b_sha256" = "$refetch_expected_sha256" ] || {
    echo "golden path failed: upstream fixture did not switch to payload B (expected=$refetch_expected_sha256 actual=$upstream_b_sha256)" >&2
    exit 1
}
[ "$actual_vault_sha256" = "$expected_vault_sha256" ] || {
    echo "golden path failed: Vault changed before refetch (expected payload A sha256=$expected_vault_sha256 actual=$actual_vault_sha256)" >&2
    exit 1
}
echo "golden refetch source switched: sha256=$upstream_b_sha256; Vault remains payload A"

refetch_headers="$TMP/refetch.headers"
refetch=$(curl -fsS -D "$refetch_headers" -X POST "$base/api/v1/items/$item_id/refetch" \
    -H "Authorization: Bearer $token")
printf 'golden refetch response: %s\n' "$refetch"
awk '$2 == 202 { found = 1 } END { exit !found }' "$refetch_headers"
refetch_job_id=$(printf '%s' "$refetch" | jq -r '.job.id // empty')
[ -n "$refetch_job_id" ] || { echo 'golden path failed: refetch returned no job id' >&2; exit 1; }
if ! printf '%s' "$refetch" | jq -e --arg item_id "$item_id" \
    '.job.type == "core.download" and .job.entity_type == "item" and .job.entity_id == $item_id and .job.payload.force == true and .job.payload.item_id == $item_id' >/dev/null; then
    echo 'golden path failed: refetch job did not expose the expected forced core.download payload' >&2
    printf '%s\n' "$refetch" >&2
    exit 1
fi
echo "golden refetch accepted: job=$refetch_job_id"

refetch_state=''
refetch_job=''
for _ in $(seq 1 90); do
    refetch_job=$(curl -fsS "$base/api/v1/jobs/$refetch_job_id" -H "Authorization: Bearer $token")
    refetch_state=$(printf '%s' "$refetch_job" | jq -r '.job.state // empty')
    [ "$refetch_state" = ready ] && break
    [ "$refetch_state" = failed ] && { echo "$refetch_job" >&2; exit 1; }
    sleep 2
done
[ "$refetch_state" = ready ] || { echo "$refetch_job" >&2; echo 'golden path failed: refetch job never reached terminal ready state' >&2; exit 1; }
if ! printf '%s' "$refetch_job" | jq -e --arg item_id "$item_id" \
    '.job.type == "core.download" and .job.entity_id == $item_id and (.job.attempts // 0) > 0 and .job.finished_at != null and .job.progress_percent == 100 and .job.progress_label != "Download skipped (already in Vault)"' >/dev/null; then
    echo 'golden path failed: refetch job completed without proving a non-skipped worker download' >&2
    printf '%s\n' "$refetch_job" >&2
    exit 1
fi
printf 'golden refetch job completed: %s\n' "$refetch_job"

refetched_item=$(curl -fsS "$base/api/v1/items/$item_id" -H "Authorization: Bearer $token")
printf '%s' "$refetched_item" | jq -e '.item.state == "ready" or .state == "ready"' >/dev/null
refetched_assets=$(curl -fsS "$base/api/v1/items/$item_id/assets" -H "Authorization: Bearer $token")
printf '%s' "$refetched_assets" | jq -e '.assets | any(.[]; .role == "vault_original" and .state == "ready")' >/dev/null
printf '%s' "$refetched_assets" | jq -e '[.assets[] | select(.role == "vault_original" and .state == "ready")] | length == 1' >/dev/null
refetched_vault_asset_path=$(printf '%s' "$refetched_assets" | jq -r '.assets[] | select(.role == "vault_original" and .state == "ready") | .path' | head -n 1)
[ -n "$refetched_vault_asset_path" ] && [ "$refetched_vault_asset_path" != "null" ] || {
    echo 'golden path failed: refetch left no ready vault_original path' >&2
    exit 1
}
final_vault_sha256=$(timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd \
    sha256sum "$refetched_vault_asset_path" | awk '{print $1}')
[ "$final_vault_sha256" = "$refetch_expected_sha256" ] || {
    echo "golden path failed: refetched Vault bytes mismatch for $refetched_vault_asset_path" >&2
    echo "expected payload B sha256=$refetch_expected_sha256 actual sha256=$final_vault_sha256" >&2
    exit 1
}
[ "$final_vault_sha256" != "$expected_vault_sha256" ] || {
    echo 'golden path failed: refetch left payload A in the Vault' >&2
    exit 1
}
printf '%s' "$refetched_assets" | jq -e --arg checksum "sha256:$refetch_expected_sha256" \
    --argjson size "$refetch_expected_size" \
    '.assets | any(.[]; .role == "vault_original" and .state == "ready" and .checksum == $checksum and .size_bytes == $size)' >/dev/null
echo "golden refetch Vault bytes verified: path=$refetched_vault_asset_path sha256=$final_vault_sha256 job=$refetch_job_id"

timeout 600s "$ROOT/tests/docker/media-server-broadcast-path.sh" \
    "$ROOT" "$base" "$token" "$stash_id" "$final_vault_sha256"

retry_stash=$(curl -fsS -X POST "$base/api/v1/stashes/with-input" \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $token" \
    -d '{"name":"Golden Retry Failure","input":{"plugin":"youtube","source":{"url":"https://www.youtube.com/watch?v=retryfail01"}},"downloadPolicy":"video"}')
retry_stash_id=$(printf '%s' "$retry_stash" | jq -r '.stash.id // empty')
[ -n "$retry_stash_id" ] || { echo 'golden path failed: retry fixture stash was not created' >&2; exit 1; }

retry_input_job_id=''
for _ in $(seq 1 15); do
    retry_input_job_id=$(curl -fsS "$base/api/v1/jobs" -H "Authorization: Bearer $token" \
        | jq -r --arg stash_id "$retry_stash_id" '[.jobs[] | select(.type == "core.add_input" and .stash_id == $stash_id)] | .[0].id // empty')
    [ -n "$retry_input_job_id" ] && break
    sleep 1
done
[ -n "$retry_input_job_id" ] || { echo 'golden path failed: no retry fixture input job' >&2; exit 1; }

retry_item_id=''
retry_state=''
retry_items=''
retry_download_job_id=''
retry_download_job=''
for _ in $(seq 1 90); do
    retry_items=$(curl -fsS "$base/api/v1/stashes/$retry_stash_id/items" -H "Authorization: Bearer $token")
    retry_item_id=$(printf '%s' "$retry_items" | jq -r '.items[0].item_id // .items[0].itemId // .items[0].id // empty')
    retry_state=$(printf '%s' "$retry_items" | jq -r '.items[0].item.state // .items[0].state // empty')
    retry_download_job_id=$(curl -fsS "$base/api/v1/jobs" -H "Authorization: Bearer $token" \
        | jq -r --arg stash_id "$retry_stash_id" '[.jobs[] | select(.type == "core.download" and .stash_id == $stash_id and .state == "failed")] | .[0].id // empty')
    if [ "$retry_state" = failed ] && [ -n "$retry_download_job_id" ]; then
        retry_download_job=$(curl -fsS "$base/api/v1/jobs/$retry_download_job_id" -H "Authorization: Bearer $token")
        break
    fi
    sleep 2
done
[ -n "$retry_item_id" ] && [ "$retry_state" = failed ] && [ -n "$retry_download_job_id" ] || {
    echo 'golden path failed: retry fixture did not produce a failed item/download job' >&2
    printf '%s\n' "$retry_items" >&2
    exit 1
}
printf '%s' "$retry_download_job" | jq -e --arg item_id "$retry_item_id" \
    '.job.type == "core.download" and .job.entity_id == $item_id and (.job.attempts // 0) > 0 and .job.last_error != null' >/dev/null
retry_assets_before=$(curl -fsS "$base/api/v1/items/$retry_item_id/assets" -H "Authorization: Bearer $token")
printf '%s' "$retry_assets_before" | jq -e '(.assets // []) | all(.[]; .role != "vault_original" or .state != "ready")' >/dev/null
printf 'golden retry failed job: %s\n' "$retry_download_job"
echo "golden retry initial failure: item=$retry_item_id job=$retry_download_job_id state=failed"

printf 'healthy\n' > "$TMP/fixture/retry-mode"
retry_upstream_sha256=$(timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd \
    curl --connect-timeout 1 --max-time 5 -fsS \
    'https://www.youtube.com/videoplayback/retryfail01' | sha256sum | awk '{print $1}')
[ "$retry_upstream_sha256" = "$retry_expected_sha256" ] || {
    echo "golden path failed: retry fixture did not switch to payload R (expected=$retry_expected_sha256 actual=$retry_upstream_sha256)" >&2
    exit 1
}
retry_items_after_repair=$(curl -fsS "$base/api/v1/stashes/$retry_stash_id/items" -H "Authorization: Bearer $token")
printf '%s' "$retry_items_after_repair" | jq -e --arg item_id "$retry_item_id" \
    '.items | length == 1 and .[0].item_id == $item_id and (.[0].item.state // .[0].state) == "failed"' >/dev/null
echo "golden retry source repaired: sha256=$retry_upstream_sha256; item remains failed"

retry_response_headers="$TMP/retry-failed.headers"
retry_response=$(curl -fsS -D "$retry_response_headers" -X POST \
    "$base/api/v1/stashes/$retry_stash_id/retry-failed" -H "Authorization: Bearer $token")
awk '$2 == 202 { found = 1 } END { exit !found }' "$retry_response_headers"
printf 'golden retry-failed response: %s\n' "$retry_response"
printf '%s' "$retry_response" | jq -e --arg stash_id "$retry_stash_id" --arg item_id "$retry_item_id" \
    '.created_count == 1 and (.jobs | length) == 1 and .jobs[0].type == "core.download" and .jobs[0].entity_type == "item" and .jobs[0].entity_id == $item_id and .jobs[0].stash_id == $stash_id and .jobs[0].payload.item_id == $item_id and .jobs[0].payload.stash_id == $stash_id and .jobs[0].payload.force == false' >/dev/null
retry_job_id=$(printf '%s' "$retry_response" | jq -r '.jobs[0].id // empty')
[ -n "$retry_job_id" ] || { echo 'golden path failed: retry-failed returned no job id' >&2; exit 1; }

retry_state=''
retry_job=''
for _ in $(seq 1 90); do
    retry_job=$(curl -fsS "$base/api/v1/jobs/$retry_job_id" -H "Authorization: Bearer $token")
    retry_state=$(printf '%s' "$retry_job" | jq -r '.job.state // empty')
    [ "$retry_state" = ready ] && break
    [ "$retry_state" = failed ] && { echo "$retry_job" >&2; exit 1; }
    sleep 2
done
[ "$retry_state" = ready ] || { echo "$retry_job" >&2; echo 'golden path failed: retry job never reached terminal ready state' >&2; exit 1; }
printf '%s' "$retry_job" | jq -e --arg item_id "$retry_item_id" \
    '.job.type == "core.download" and .job.entity_id == $item_id and (.job.attempts // 0) > 0 and .job.last_error == null and .job.finished_at != null and .job.progress_percent == 100 and .job.progress_label != "Download skipped (already in Vault)"' >/dev/null
echo "golden retry job completed: job=$retry_job_id state=ready"

retry_final_items=$(curl -fsS "$base/api/v1/stashes/$retry_stash_id/items" -H "Authorization: Bearer $token")
printf '%s' "$retry_final_items" | jq -e --arg item_id "$retry_item_id" \
    '.items | length == 1 and .[0].item_id == $item_id and (.[0].item.state // .[0].state) == "ready"' >/dev/null
retry_assets=$(curl -fsS "$base/api/v1/items/$retry_item_id/assets" -H "Authorization: Bearer $token")
printf '%s' "$retry_assets" | jq -e '.assets | any(.[]; .role == "vault_original" and .state == "ready")' >/dev/null
printf '%s' "$retry_assets" | jq -e '[.assets[] | select(.role == "vault_original" and .state == "ready")] | length == 1' >/dev/null
retry_vault_asset_path=$(printf '%s' "$retry_assets" | jq -r '.assets[] | select(.role == "vault_original" and .state == "ready") | .path' | head -n 1)
[ -n "$retry_vault_asset_path" ] && [ "$retry_vault_asset_path" != "null" ] || {
    echo 'golden path failed: retry left no ready vault_original path' >&2
    exit 1
}
retry_final_sha256=$(timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd \
    sha256sum "$retry_vault_asset_path" | awk '{print $1}')
[ "$retry_final_sha256" = "$retry_expected_sha256" ] || {
    echo "golden path failed: retry Vault bytes mismatch for $retry_vault_asset_path" >&2
    echo "expected sha256=$retry_expected_sha256 actual sha256=$retry_final_sha256" >&2
    exit 1
}
printf '%s' "$retry_assets" | jq -e --arg checksum "sha256:$retry_expected_sha256" \
    --argjson size "$retry_expected_size" \
    '.assets | any(.[]; .role == "vault_original" and .state == "ready" and .checksum == $checksum and .size_bytes == $size)' >/dev/null
echo "golden retry Vault bytes verified: item=$retry_item_id path=$retry_vault_asset_path sha256=$retry_final_sha256 job=$retry_job_id"

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

printf 'healthy\n' > "$TMP/fixture/caption-mode"
primary_download=$(curl -fsS "$base/api/v1/jobs" -H "Authorization: Bearer $token" \
    | jq -c --arg item_id "$item_id" --arg stash_id "$stash_id" '[.jobs[] | select(.type == "core.download" and .entity_id == $item_id and .stash_id == $stash_id)] | sort_by(.created_at // .createdAt) | last // null')
echo "golden pre-broadcast state: item=$item_id item_state=ready primary_download=$primary_download"
caption_baseline_ids=$(curl -fsS "$base/api/v1/jobs" -H "Authorization: Bearer $token" \
    | jq --arg item_id "$item_id" --arg stash_id "$stash_id" '[.jobs[] | select(.type == "core.acquire_assets" and .entity_id == $item_id and .stash_id == $stash_id) | .id]')
echo "golden caption acquisition baseline: $caption_baseline_ids"
broadcast=$(curl -fsS -X POST "$base/api/v1/stashes/$stash_id/broadcasts" \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $token" \
    -d '{"type":"podcast","name":"Golden Podcast","settings":{"media_kind":"video","captions":"creator_only","caption_languages":"en"}}')
broadcast_id=$(printf '%s' "$broadcast" | jq -r '.broadcast.id')

caption_job_id=''
caption_job=''
for _ in $(seq 1 90); do
    caption_job_id=$(curl -fsS "$base/api/v1/jobs" -H "Authorization: Bearer $token" \
        | jq -r --arg item_id "$item_id" --arg stash_id "$stash_id" --argjson baseline "$caption_baseline_ids" \
            '[.jobs[] | select(.type == "core.acquire_assets" and .entity_id == $item_id and .stash_id == $stash_id) as $job | select(($baseline | index($job.id)) == null) | select(($job.payload.roles // []) | index("captions") != null) | $job] | .[0].id // empty')
    [ -n "$caption_job_id" ] && break
    sleep 1
done
[ -n "$caption_job_id" ] || { echo 'golden path failed: caption broadcast dispatched no core.acquire_assets job' >&2; exit 1; }
caption_job=$(curl -fsS "$base/api/v1/jobs/$caption_job_id" -H "Authorization: Bearer $token")
printf '%s' "$caption_job" | jq -e --arg item_id "$item_id" \
    '.job.type == "core.acquire_assets" and .job.entity_id == $item_id and .job.payload.roles == ["captions"] and .job.payload.provider_options.include_captions == true and .job.payload.provider_options.include_auto_captions == false and .job.payload.provider_options.caption_languages == "en"' >/dev/null || {
    echo 'golden path failed: caption acquisition job payload was wrong' >&2
    printf '%s\n' "$caption_job" >&2
    exit 1
}
echo "golden caption acquisition dispatched: job=$caption_job_id"

caption_state=''
for _ in $(seq 1 90); do
    caption_job=$(curl -fsS "$base/api/v1/jobs/$caption_job_id" -H "Authorization: Bearer $token")
    caption_state=$(printf '%s' "$caption_job" | jq -r '.job.state // empty')
    [ "$caption_state" = ready ] && break
    [ "$caption_state" = failed ] && { echo "$caption_job" >&2; exit 1; }
    sleep 2
done
[ "$caption_state" = ready ] || { echo "$caption_job" >&2; echo 'golden path failed: caption acquisition job never reached ready' >&2; exit 1; }
printf '%s' "$caption_job" | jq -e '.job.attempts > 0 and .job.finished_at != null and .job.progress_percent == 100 and .job.progress_label == "Asset acquisition complete"' >/dev/null
echo "golden caption acquisition completed: job=$caption_job_id"
docker logs "$FIXTURE_CONTAINER" 2>&1 | grep -F '"GET /api/timedtext?v=goldenvid01&lang=en&fmt=vtt HTTP/' >/dev/null || {
    echo 'golden path failed: YouTube timed-text endpoint was not requested' >&2
    docker logs "$FIXTURE_CONTAINER" >&2 || true
    exit 1
}
echo 'golden caption source request verified: /api/timedtext?v=goldenvid01&lang=en&fmt=vtt'

caption_assets=$(curl -fsS "$base/api/v1/items/$item_id/assets" -H "Authorization: Bearer $token")
caption_path=$(printf '%s' "$caption_assets" | jq -r '.assets[] | select(.role == "subtitle" and .kind == "subtitle" and .state == "ready" and .language == "en") | .path' | head -n 1)
[ -n "$caption_path" ] && [ "$caption_path" != "null" ] || { echo 'golden path failed: no ready English subtitle asset' >&2; printf '%s\n' "$caption_assets" >&2; exit 1; }
caption_sha256=$(timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$caption_path" | awk '{print $1}')
[ "$caption_sha256" = "$caption_expected_sha256" ] || { echo "golden path failed: caption Vault checksum mismatch expected=$caption_expected_sha256 actual=$caption_sha256" >&2; exit 1; }
printf '%s' "$caption_assets" | jq -e --arg checksum "sha256:$caption_expected_sha256" '.assets | any(.[]; .role == "subtitle" and .kind == "subtitle" and .state == "ready" and .language == "en" and .mime_type == "text/vtt" and .checksum == $checksum)' >/dev/null
echo "golden caption Vault bytes verified: path=$caption_path sha256=$caption_sha256"

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
feed=$(curl -fsS "$published_url")
printf '%s' "$feed" | grep -q 'Golden Path Video'
transcript_url=$(printf '%s' "$feed" | sed -n 's/.*<podcast:transcript[^>]*url="\([^"]*\)".*/\1/p' | head -n 1)
[ -n "$transcript_url" ] || { echo 'golden path failed: Podcast feed has no transcript URL' >&2; printf '%s\n' "$feed" >&2; exit 1; }
transcript=$(curl -fsS "$transcript_url")
printf '%s' "$transcript" | grep -q 'Deterministic fixture caption'
echo "golden Podcast caption output verified: broadcast=$broadcast_id transcript=$transcript_url"

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

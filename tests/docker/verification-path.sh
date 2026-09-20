#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-verification-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_VERIFICATION_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_VERIFICATION_PORT:-18477}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
export SIGNING_KEY="${STASHD_VERIFICATION_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"

curl_request() {
    command curl --connect-timeout 1 --max-time "${STASHD_VERIFICATION_CURL_TIMEOUT:-15}" "$@"
}

TMP=$(mktemp -d)
scheduler_success="$TMP/scheduler-success.output"
scheduler_mismatch="$TMP/scheduler-mismatch.output"
success_jobs="$TMP/success.jobs.json"
success_job="$TMP/success.job.json"
success_inspect="$TMP/success.inspect.json"
success_assets="$TMP/success.assets.json"
success_item="$TMP/success.item.json"
success_health="$TMP/success.health.json"
success_health_headers="$TMP/success.health.headers"
mismatch_jobs="$TMP/mismatch.jobs.json"
mismatch_job="$TMP/mismatch.job.json"
mismatch_inspect="$TMP/mismatch.inspect.json"
mismatch_assets="$TMP/mismatch.assets.json"
mismatch_item="$TMP/mismatch.item.json"

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        echo '--- verification scheduler success output ---' >&2
        cat "$scheduler_success" >&2 2>/dev/null || true
        echo '--- verification scheduler mismatch output ---' >&2
        cat "$scheduler_mismatch" >&2 2>/dev/null || true
        echo '--- verification job and asset diagnostics ---' >&2
        for file in "$success_jobs" "$success_job" "$success_inspect" "$success_assets" "$success_item" "$success_health" \
            "$mismatch_jobs" "$mismatch_job" "$mismatch_inspect" "$mismatch_assets" "$mismatch_item"; do
            [ -f "$file" ] && { echo "--- $file ---" >&2; cat "$file" >&2 || true; }
        done
        echo '--- Stashd logs ---' >&2
        docker compose -f "$ROOT/docker-compose.yml" logs stashd >&2 2>/dev/null || true
        echo '--- PostgreSQL logs ---' >&2
        docker compose -f "$ROOT/docker-compose.yml" logs postgres >&2 2>/dev/null || true
    fi
    docker compose -f "$ROOT/docker-compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

export STASHD_DATA_DIR="$TMP/data"
export STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"

if [ "${STASHD_VERIFICATION_SKIP_BUILD:-0}" != "1" ]; then
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
    -d '{"username":"verification","password":"verification-password"}' >/dev/null
curl_request -fsS -X POST "$base/api/v1/auth/login" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"verification","password":"verification-password"}' >/dev/null
token=$(curl_request -fsS -X POST "$base/api/v1/auth/tokens" \
    -H 'Content-Type: application/json' -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"name":"verification-boundary"}' | jq -r '.token // empty')
[ -n "$token" ] || { echo 'Verification proof failed: no API token' >&2; exit 1; }

docker compose -f "$ROOT/docker-compose.yml" cp \
    "$ROOT/tests/docker/verification-boundary.php" stashd:/tmp/verification-boundary.php

success_fixture=$(docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/verification-boundary.php seed-success)
success_asset_id=$(printf '%s' "$success_fixture" | jq -r '.asset_id')
success_item_id=$(printf '%s' "$success_fixture" | jq -r '.item_id')
success_checksum=$(printf '%s' "$success_fixture" | jq -r '.expected_checksum')
jq -e '.storage.state == "ready" and .storage.readable == true and .storage.writable == true and .last_verified_at == null' \
    <<<"$success_fixture" >/dev/null

timeout "${STASHD_VERIFICATION_COMMAND_TIMEOUT:-60}s" \
    docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php tempest stashd:verification-tick >"$scheduler_success" 2>&1
grep -q 'Scheduled 1 automatic verification job(s).' "$scheduler_success"

success_job_id=''
for _ in $(seq 1 30); do
    curl_request -fsS -H "Authorization: Bearer $token" "$base/api/v1/jobs" >"$success_jobs"
    success_job_id=$(jq -r --arg aid "$success_asset_id" '
        [.jobs[] | select(.type == "core.verify_vault" and .entity_type == "asset" and .entity_id == $aid)]
        | if length == 1 then .[0].id else "" end
    ' "$success_jobs")
    [ -n "$success_job_id" ] && break
    sleep 1
done
[ -n "$success_job_id" ] || { echo 'Verification proof failed: exact success job was not dispatched' >&2; exit 1; }

success_terminal=''
for _ in $(seq 1 "${STASHD_VERIFICATION_JOB_TIMEOUT:-120}"); do
    curl_request -fsS -H "Authorization: Bearer $token" \
        "$base/api/v1/jobs/$success_job_id" >"$success_job"
    success_terminal=$(jq -r '.job.state // empty' "$success_job")
    case "$success_terminal" in
        ready|failed|cancelled) break ;;
    esac
    sleep 1
done
jq -e --arg aid "$success_asset_id" --arg checksum "$success_checksum" '
    .job.type == "core.verify_vault" and
    .job.entity_type == "asset" and .job.entity_id == $aid and
    .job.state == "ready" and (.job.attempts // 0) >= 1 and
    .job.started_at != null and .job.finished_at != null and
    .job.progress_current == 1 and .job.progress_total == 1 and
    .job.progress_percent == 100 and
    .job.payload.asset_id == $aid and
    .job.payload.result.scope == "asset" and
    .job.payload.result.asset_id == $aid and
    .job.payload.result.outcome == "ok" and
    .job.payload.result.expected_checksum == $checksum and
    .job.payload.result.observed_checksum == $checksum
' "$success_job" >/dev/null

docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/verification-boundary.php inspect "$success_asset_id" >"$success_inspect"
jq -e --arg aid "$success_asset_id" --arg jid "$success_job_id" --arg checksum "$success_checksum" '
    .asset.id == $aid and .asset.state == "ready" and .asset.last_verified_at != null and
    ([.events[] | select(.event_type == "fixity_check" and .outcome == "success" and
        .job_id == $jid and .expected_checksum == $checksum and .observed_checksum == $checksum)] | length) == 1
' "$success_inspect" >/dev/null

curl_request -fsS -H "Authorization: Bearer $token" \
    "$base/api/v1/items/$success_item_id/assets" >"$success_assets"
curl_request -fsS -H "Authorization: Bearer $token" \
    "$base/api/v1/items/$success_item_id" >"$success_item"
curl_request -sS -D "$success_health_headers" -H "Authorization: Bearer $token" \
    "$base/api/v1/system/health" >"$success_health"

if ! jq empty "$success_health" >/dev/null 2>&1; then
    python3 - "$success_health" <<'PY'
import html
import json
import re
import sys

body = open(sys.argv[1], encoding='utf-8').read()
match = re.search(r'<script id="tempest-hydration"[^>]*>(.*?)</script>', body, re.S)
if match:
    hydration = json.loads(html.unescape(match.group(1)))
    print(json.loads(hydration.get('stacktrace', '{}')))
else:
    print(body[:2000])
raise SystemExit('system health did not return JSON')
PY
fi

python3 - "$success_assets" "$success_asset_id" "$success_checksum" <<'PY'
import datetime
import json
import sys

payload = json.load(open(sys.argv[1], encoding='utf-8'))
asset_id, checksum = sys.argv[2:]
asset = next(asset for asset in payload['assets'] if asset['id'] == asset_id)
assert asset['state'] == 'ready', asset
assert asset['checksum'] == checksum, asset
assert asset['fixity_status'] == 'verified', asset
assert asset['preservation_health'] == 'healthy', asset
assert asset['last_verified_at'], asset
assert asset['verification_due_at'], asset
due = datetime.datetime.fromisoformat(asset['verification_due_at'].replace('Z', '+00:00'))
assert due > datetime.datetime.now(datetime.timezone.utc), asset
PY
jq -e '.item.preservation_health == "healthy"' "$success_item" >/dev/null
jq -e '
    .preservation.health == "healthy" and
    .preservation.total_preserved_assets == 1 and
    .preservation.verifiable_assets == 1 and
    .preservation.fixity_counts.verified == 1 and
    .preservation.health_counts.healthy == 1 and
    .preservation.storage_unavailable == false
' "$success_health" >/dev/null

mismatch_fixture=$(docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/verification-boundary.php seed-mismatch)
mismatch_asset_id=$(printf '%s' "$mismatch_fixture" | jq -r '.asset_id')
mismatch_item_id=$(printf '%s' "$mismatch_fixture" | jq -r '.item_id')
mismatch_checksum=$(printf '%s' "$mismatch_fixture" | jq -r '.expected_checksum')
mismatch_observed=$(printf '%s' "$mismatch_fixture" | jq -r '.observed_checksum')
[ "$mismatch_checksum" != "$mismatch_observed" ]

timeout "${STASHD_VERIFICATION_COMMAND_TIMEOUT:-60}s" \
    docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php tempest stashd:verification-tick >"$scheduler_mismatch" 2>&1
grep -q 'Scheduled 1 automatic verification job(s).' "$scheduler_mismatch"

mismatch_job_id=''
for _ in $(seq 1 30); do
    curl_request -fsS -H "Authorization: Bearer $token" "$base/api/v1/jobs" >"$mismatch_jobs"
    mismatch_job_id=$(jq -r --arg aid "$mismatch_asset_id" '
        [.jobs[] | select(.type == "core.verify_vault" and .entity_type == "asset" and .entity_id == $aid)]
        | if length == 1 then .[0].id else "" end
    ' "$mismatch_jobs")
    [ -n "$mismatch_job_id" ] && break
    sleep 1
done
[ -n "$mismatch_job_id" ] || { echo 'Verification sensitivity failed: exact mismatch job was not dispatched' >&2; exit 1; }

for _ in $(seq 1 "${STASHD_VERIFICATION_JOB_TIMEOUT:-120}"); do
    curl_request -fsS -H "Authorization: Bearer $token" \
        "$base/api/v1/jobs/$mismatch_job_id" >"$mismatch_job"
    mismatch_terminal=$(jq -r '.job.state // empty' "$mismatch_job")
    case "$mismatch_terminal" in
        ready|failed|cancelled) break ;;
    esac
    sleep 1
done
jq -e --arg aid "$mismatch_asset_id" --arg expected "$mismatch_checksum" --arg observed "$mismatch_observed" '
    .job.type == "core.verify_vault" and .job.entity_id == $aid and
    .job.state == "ready" and (.job.attempts // 0) >= 1 and
    .job.started_at != null and .job.finished_at != null and
    .job.progress_current == 1 and .job.progress_total == 1 and
    .job.progress_percent == 100 and
    .job.payload.result.scope == "asset" and
    .job.payload.result.asset_id == $aid and
    .job.payload.result.outcome == "checksum_mismatch" and
    .job.payload.result.expected_checksum == $expected and
    .job.payload.result.observed_checksum == $observed
' "$mismatch_job" >/dev/null

docker compose -f "$ROOT/docker-compose.yml" exec -T stashd \
    php /tmp/verification-boundary.php inspect "$mismatch_asset_id" >"$mismatch_inspect"
jq -e --arg aid "$mismatch_asset_id" --arg jid "$mismatch_job_id" --arg expected "$mismatch_checksum" --arg observed "$mismatch_observed" '
    .asset.id == $aid and .asset.state == "stale" and .asset.last_verified_at == null and
    ([.events[] | select(.event_type == "fixity_check" and .outcome == "mismatch" and
        .job_id == $jid and .expected_checksum == $expected and .observed_checksum == $observed)] | length) == 1 and
    ([.events[] | select(.event_type == "fixity_check" and .outcome == "success")] | length) == 0
' "$mismatch_inspect" >/dev/null

curl_request -fsS -H "Authorization: Bearer $token" \
    "$base/api/v1/items/$mismatch_item_id/assets" >"$mismatch_assets"
curl_request -fsS -H "Authorization: Bearer $token" \
    "$base/api/v1/items/$mismatch_item_id" >"$mismatch_item"
python3 - "$mismatch_assets" "$mismatch_asset_id" "$mismatch_checksum" <<'PY'
import json
import sys

payload = json.load(open(sys.argv[1], encoding='utf-8'))
asset_id, checksum = sys.argv[2:]
asset = next(asset for asset in payload['assets'] if asset['id'] == asset_id)
assert asset['state'] == 'stale', asset
assert asset['checksum'] == checksum, asset
assert asset['fixity_status'] == 'mismatch', asset
assert asset['preservation_health'] == 'critical', asset
assert asset['last_verified_at'] is None, asset
assert asset['verification_due_at'] is None, asset
PY
jq -e '.item.preservation_health == "critical"' "$mismatch_item" >/dev/null

echo "production Vault verification boundary passed: success_asset=$success_asset_id success_job=$success_job_id mismatch_asset=$mismatch_asset_id mismatch_job=$mismatch_job_id expected=$success_checksum sensitivity=checksum-mismatch"

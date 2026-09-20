#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-mercure-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_MERCURE_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_MERCURE_PORT:-18476}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
export SIGNING_KEY="${STASHD_MERCURE_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"
export MERCURE_JWT_SECRET="${STASHD_MERCURE_SECRET:-stashd-mercure-boundary-secret-32-bytes}"

curl_request() {
    command curl --connect-timeout 1 --max-time "${STASHD_MERCURE_CURL_TIMEOUT:-15}" "$@"
}

TMP=$(mktemp -d)
sse_pid=''
wrong_sse_pid=''
sse_headers="$TMP/sse.headers"
sse_output="$TMP/sse.output"
sse_stderr="$TMP/sse.stderr"
wrong_sse_headers="$TMP/wrong-sse.headers"
wrong_sse_output="$TMP/wrong-sse.output"
wrong_sse_stderr="$TMP/wrong-sse.stderr"
anonymous_body="$TMP/anonymous.body"
anonymous_headers="$TMP/anonymous.headers"
subscription_body="$TMP/subscription.body"
subscription_headers="$TMP/subscription.headers"
stash_body="$TMP/stash.body"
stash_headers="$TMP/stash.headers"
wrong_stash_body="$TMP/wrong-stash.body"

stop_process() {
    pid="$1"
    if [ -n "$pid" ]; then
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    fi
}

cleanup() {
    status=$?
    stop_process "${sse_pid:-}"
    stop_process "${wrong_sse_pid:-}"
    if [ "$status" -ne 0 ]; then
        echo '--- public SSE headers ---' >&2
        cat "$sse_headers" >&2 2>/dev/null || true
        echo '--- public SSE output ---' >&2
        cat "$sse_output" >&2 2>/dev/null || true
        echo '--- public SSE stderr ---' >&2
        cat "$sse_stderr" >&2 2>/dev/null || true
        echo '--- wrong-topic SSE headers ---' >&2
        cat "$wrong_sse_headers" >&2 2>/dev/null || true
        echo '--- wrong-topic SSE output ---' >&2
        cat "$wrong_sse_output" >&2 2>/dev/null || true
        echo '--- wrong-topic SSE stderr ---' >&2
        cat "$wrong_sse_stderr" >&2 2>/dev/null || true
        echo '--- anonymous Mercure response ---' >&2
        cat "$anonymous_headers" "$anonymous_body" >&2 2>/dev/null || true
        echo '--- subscription response ---' >&2
        cat "$subscription_headers" "$subscription_body" >&2 2>/dev/null || true
        echo '--- stash trigger responses ---' >&2
        cat "$stash_headers" "$stash_body" "$wrong_stash_body" >&2 2>/dev/null || true
        echo '--- Stashd and Mercure logs ---' >&2
        docker compose -f "$ROOT/docker-compose.yml" logs stashd >&2 2>/dev/null || true
    fi
    docker compose -f "$ROOT/docker-compose.yml" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

export STASHD_DATA_DIR="$TMP/data"
export STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
[ "${#MERCURE_JWT_SECRET}" -ge 32 ] || { echo 'Mercure proof failed: JWT secret is shorter than 32 characters' >&2; exit 1; }

if [ "${STASHD_MERCURE_SKIP_BUILD:-0}" != "1" ]; then
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

anonymous_body="$TMP/anonymous.body"
anonymous_headers="$TMP/anonymous.headers"
anonymous_code=''
for _ in $(seq 1 30); do
    anonymous_code=$(curl_request -sS -D "$anonymous_headers" -o "$anonymous_body" -w '%{http_code}' \
        "$base/.well-known/mercure?topic=stashd%2Fevents" || true)
    [ "$anonymous_code" = 401 ] && break
    sleep 1
done
[ "$anonymous_code" = 401 ] || {
    echo "Mercure proof failed: anonymous subscription returned HTTP $anonymous_code" >&2
    exit 1
}

curl_request -fsS -X POST "$base/api/v1/auth/setup" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"mercure","password":"mercure-password"}' >/dev/null
curl_request -fsS -X POST "$base/api/v1/auth/login" -H 'Content-Type: application/json' \
    -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"username":"mercure","password":"mercure-password"}' >/dev/null
token=$(curl_request -fsS -X POST "$base/api/v1/auth/tokens" \
    -H 'Content-Type: application/json' -c "$cookie_jar" -b "$cookie_jar" \
    -d '{"name":"mercure-boundary"}' | jq -r '.token // empty')
[ -n "$token" ] || { echo 'Mercure proof failed: no API token' >&2; exit 1; }

subscription_body="$TMP/subscription.body"
subscription_headers="$TMP/subscription.headers"
subscription_code=$(curl_request -sS -D "$subscription_headers" -o "$subscription_body" -w '%{http_code}' \
    -H "Authorization: Bearer $token" -b "$cookie_jar" -c "$cookie_jar" \
    "$base/api/v1/events/subscription")
[ "$subscription_code" = 200 ] || {
    echo "Mercure proof failed: subscription endpoint returned HTTP $subscription_code" >&2
    exit 1
}
jq -e '.ok == true' "$subscription_body" >/dev/null
grep -Eiq '^Set-Cookie: mercureAuthorization=[^;]+;.*Path=/\.well-known/mercure' "$subscription_headers"
awk '($0 !~ /^#/ || $0 ~ /^#HttpOnly_/) && $3 == "/.well-known/mercure" && $6 == "mercureAuthorization" { found = 1 } END { exit ! found }' "$cookie_jar"

sse_url="$base/.well-known/mercure?topic=stashd%2Fevents"
command curl --connect-timeout 1 --max-time "${STASHD_MERCURE_SSE_TIMEOUT:-30}" \
    --no-buffer -sS -D "$sse_headers" -o "$sse_output" -b "$cookie_jar" \
    "$sse_url" 2>"$sse_stderr" &
sse_pid=$!

sse_ready=0
for _ in $(seq 1 100); do
    if ! kill -0 "$sse_pid" 2>/dev/null; then
        echo 'Mercure proof failed: authenticated SSE process exited before readiness' >&2
        exit 1
    fi
    if grep -Eiq '^HTTP/[^ ]+[[:space:]]+200' "$sse_headers" 2>/dev/null \
        && grep -Eiq '^Content-Type: text/event-stream' "$sse_headers" 2>/dev/null; then
        sse_ready=1
        break
    fi
    sleep 0.1
done
[ "$sse_ready" = 1 ] || { echo 'Mercure proof failed: SSE headers never reached ready state' >&2; exit 1; }

stash_name="Mercure boundary ${COMPOSE_PROJECT_NAME}"
stash_body="$TMP/stash.body"
stash_headers="$TMP/stash.headers"
stash_code=$(curl_request -sS -D "$stash_headers" -o "$stash_body" -w '%{http_code}' \
    -X POST "$base/api/v1/stashes" -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token" -d "$(jq -nc --arg name "$stash_name" '{name: $name}')")
[ "$stash_code" = 201 ] || {
    echo "Mercure proof failed: public stash creation returned HTTP $stash_code" >&2
    exit 1
}
stash_id=$(jq -r '.stash.id // empty' "$stash_body")
[ -n "$stash_id" ] || { echo 'Mercure proof failed: stash creation returned no id' >&2; exit 1; }

event_deadline=$(( $(date +%s) + ${STASHD_MERCURE_EVENT_TIMEOUT:-15} ))
while [ "$(date +%s)" -lt "$event_deadline" ]; do
    if grep -q '"event":"activity.created"' "$sse_output" 2>/dev/null; then
        break
    fi
    if ! kill -0 "$sse_pid" 2>/dev/null; then
        echo 'Mercure proof failed: SSE process exited before correlated event arrived' >&2
        exit 1
    fi
    sleep 0.1
done

python3 - "$sse_output" "$stash_id" "$stash_name" <<'PY'
import datetime
import json
import sys

path, expected_stash_id, expected_name = sys.argv[1:]
text = open(path, encoding='utf-8').read()

for block in text.replace('\r\n', '\n').split('\n\n'):
    data = '\n'.join(line[6:] for line in block.splitlines() if line.startswith('data: '))
    if not data:
        continue
    payload = json.loads(data)
    if payload.get('event') != 'activity.created':
        continue
    if payload.get('stash_id') != expected_stash_id:
        continue

    assert payload.get('type') == 'stash.created', payload
    assert payload.get('entity_type') == 'stash', payload
    assert payload.get('entity_id') == expected_stash_id, payload
    assert payload.get('message') == f'Stash "{expected_name}" created.', payload
    assert isinstance(payload.get('id'), str) and payload['id'], payload
    created_at = payload.get('created_at')
    assert isinstance(created_at, str) and created_at, payload
    parsed = datetime.datetime.fromisoformat(created_at.replace('Z', '+00:00'))
    assert parsed.tzinfo is not None, payload
    print(json.dumps(payload, sort_keys=True))
    break
else:
    raise SystemExit('no structurally valid correlated activity.created event found')
PY

for _ in $(seq 1 10); do
    if ! kill -0 "$sse_pid" 2>/dev/null; then
        echo 'Mercure proof failed: SSE connection closed after event delivery' >&2
        exit 1
    fi
    sleep 0.2
done

wrong_topic_url="$base/.well-known/mercure?topic=stashd%2Fevents-wrong"
command curl --connect-timeout 1 --max-time 10 --no-buffer -sS \
    -D "$wrong_sse_headers" -o "$wrong_sse_output" -b "$cookie_jar" \
    "$wrong_topic_url" 2>"$wrong_sse_stderr" &
wrong_sse_pid=$!

wrong_ready=0
for _ in $(seq 1 100); do
    if ! kill -0 "$wrong_sse_pid" 2>/dev/null; then
        echo 'Mercure sensitivity failed: wrong-topic SSE process exited before readiness' >&2
        exit 1
    fi
    if grep -Eiq '^HTTP/[^ ]+[[:space:]]+200' "$wrong_sse_headers" 2>/dev/null \
        && grep -Eiq '^Content-Type: text/event-stream' "$wrong_sse_headers" 2>/dev/null; then
        wrong_ready=1
        break
    fi
    sleep 0.1
done
[ "$wrong_ready" = 1 ] || { echo 'Mercure sensitivity failed: wrong-topic SSE did not become ready' >&2; exit 1; }

wrong_stash_name="Mercure wrong-topic ${COMPOSE_PROJECT_NAME}"
wrong_stash_body="$TMP/wrong-stash.body"
wrong_stash_code=$(curl_request -sS -o "$wrong_stash_body" -w '%{http_code}' \
    -X POST "$base/api/v1/stashes" -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token" -d "$(jq -nc --arg name "$wrong_stash_name" '{name: $name}')")
[ "$wrong_stash_code" = 201 ] || { echo "Mercure sensitivity failed: trigger returned HTTP $wrong_stash_code" >&2; exit 1; }
wrong_stash_id=$(jq -r '.stash.id // empty' "$wrong_stash_body")
[ -n "$wrong_stash_id" ] || { echo 'Mercure sensitivity failed: trigger returned no stash id' >&2; exit 1; }

wrong_deadline=$(( $(date +%s) + 3 ))
while [ "$(date +%s)" -lt "$wrong_deadline" ]; do
    if grep -q "$wrong_stash_id" "$wrong_sse_output" 2>/dev/null; then
        echo 'Mercure sensitivity failed: wrong topic received the published stash event' >&2
        exit 1
    fi
    sleep 0.1
done

echo "production Mercure boundary passed: stash=$stash_id event=activity.created topic=stashd/events liveness=kept-alive sensitivity=wrong-topic-isolated"

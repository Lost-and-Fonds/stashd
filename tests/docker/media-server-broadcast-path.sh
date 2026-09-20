#!/usr/bin/env bash
set -euo pipefail
ROOT=$1; BASE=$2; TOKEN=$3; STASH_ID=$4; SOURCE_SHA256=$5
COMPOSE_FILES=(-f "$ROOT/docker-compose.yml")
[ "${STASHD_GOLDEN_APPARMOR:-0}" = 1 ] && COMPOSE_FILES+=(-f "$ROOT/docker-compose.apparmor.yml")
TMP=$(mktemp -d)
JELLYFIN_CONTAINER="${COMPOSE_PROJECT_NAME}-jellyfin-fixture"; PLEX_CONTAINER="${COMPOSE_PROJECT_NAME}-plex-fixture"
cleanup() { status=$?; if [ "$status" -ne 0 ]; then cat "$TMP"/*/requests.jsonl >&2 2>/dev/null || true; docker compose "${COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true; fi; docker rm -f "$JELLYFIN_CONTAINER" "$PLEX_CONTAINER" >/dev/null 2>&1 || true; rm -rf "$TMP"; exit "$status"; }
trap cleanup EXIT INT TERM
network="${COMPOSE_PROJECT_NAME}_default"; mkdir -p "$TMP/jellyfin" "$TMP/plex"
cp "$ROOT/tests/docker/media-server-fixture.py" "$TMP/fixture.py"
: > "$TMP/jellyfin/requests.jsonl"; : > "$TMP/plex/requests.jsonl"
docker run -d --name "$JELLYFIN_CONTAINER" --network "$network" --network-alias jellyfin.test -v "$TMP/jellyfin:/fixture" -v "$TMP/fixture.py:/fixture.py:ro" python:3.12-slim python /fixture.py jellyfin >/dev/null
docker run -d --name "$PLEX_CONTAINER" --network "$network" --network-alias plex.test -v "$TMP/plex:/fixture" -v "$TMP/fixture.py:/fixture.py:ro" python:3.12-slim python /fixture.py plex >/dev/null
curl() { command curl --connect-timeout 1 --max-time "${STASHD_GOLDEN_CURL_TIMEOUT:-15}" "$@"; }
post_json() { curl -fsS -X POST "$1" -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" -d "$2"; }
get_json() { curl -fsS "$1" -H "Authorization: Bearer $TOKEN"; }
for _ in $(seq 1 30); do
    docker compose "${COMPOSE_FILES[@]}" exec -T stashd curl -fsS --connect-timeout 1 --max-time 2 http://jellyfin.test/System/Info/Public >/dev/null 2>&1 && break
    sleep 1
done
for _ in $(seq 1 30); do
    docker compose "${COMPOSE_FILES[@]}" exec -T stashd curl -fsS --connect-timeout 1 --max-time 2 http://plex.test/identity >/dev/null 2>&1 && break
    sleep 1
done
job_ready() { local id=$1 job='' state=''; for _ in $(seq 1 90); do job=$(get_json "$BASE/api/v1/jobs/$id"); state=$(printf '%s' "$job" | jq -r '.job.state // empty'); [ "$state" = ready ] && { printf '%s' "$job"; return; }; [ "$state" = failed ] && { printf '%s\n' "$job" >&2; return 1; }; sleep 2; done; printf '%s\n' "$job" >&2; return 1; }
request_count() { wc -l < "$1"; }
assert_request() { jq -e --arg path "$2" --arg header "$3" --arg value "$4" 'select(.path == $path and .headers[$header] == $value)' "$1" >/dev/null; }
assert_request_since() { tail -n +$(( $2 + 1 )) "$1" | jq -e --arg path "$3" --arg header "$4" --arg value "$5" 'select(.path == $path and .headers[$header] == $value)' >/dev/null; }
assert_query() { jq -e --arg path "$2" --arg key "$3" --arg value "$4" 'select(.path == $path and .query[$key][0] == $value)' "$1" >/dev/null; }
assert_query_since() { tail -n +$(( $2 + 1 )) "$1" | jq -e --arg path "$3" --arg key "$4" --arg value "$5" 'select(.path == $path and .query[$key][0] == $value)' >/dev/null; }

# Jellyfin connection, discovery, non-empty build, materialization, finalize, action, failure, recovery.
jellyfin_connection=$(post_json "$BASE/api/v1/connections" '{"plugin_key":"jellyfin","name":"Golden Jellyfin","endpoint":"http://jellyfin.test","token":"golden-media-server-token"}')
jellyfin_connection_id=$(printf '%s' "$jellyfin_connection" | jq -r '.connection.id')
jellyfin_test=$(post_json "$BASE/api/v1/connections/$jellyfin_connection_id/operations/test_connection" '{}'); printf 'jellyfin test connection: %s\n' "$jellyfin_test"
printf '%s' "$jellyfin_test" | jq -e '.values | any(.[]; .key == "ok" and .value == "true")' >/dev/null
jellyfin_libraries=$(post_json "$BASE/api/v1/connections/$jellyfin_connection_id/operations/list_libraries" '{}'); printf 'jellyfin libraries: %s\n' "$jellyfin_libraries"
printf '%s' "$jellyfin_libraries" | jq -e '.choices | any(.value == "fixture-library")' >/dev/null
assert_request "$TMP/jellyfin/requests.jsonl" /System/Info/Public x-emby-token golden-media-server-token
assert_request "$TMP/jellyfin/requests.jsonl" /Library/MediaFolders x-emby-token golden-media-server-token
jellyfin_refresh_before=$(request_count "$TMP/jellyfin/requests.jsonl")
jellyfin_broadcast=$(post_json "$BASE/api/v1/stashes/$STASH_ID/broadcasts" '{"type":"jellyfin","name":"Golden Jellyfin","settings":{"media_server_connection_id":"'"$jellyfin_connection_id"'","library_id":"fixture-library","captions":"off"}}')
jellyfin_id=$(printf '%s' "$jellyfin_broadcast" | jq -r '.broadcast.id'); jellyfin_build_job_id=$(printf '%s' "$jellyfin_broadcast" | jq -r '.build_job_id'); jellyfin_build_job=$(job_ready "$jellyfin_build_job_id"); printf 'jellyfin build job: %s\n' "$jellyfin_build_job"
jellyfin_current=$(get_json "$BASE/api/v1/broadcasts/$jellyfin_id"); printf '%s' "$jellyfin_current" | jq -e '.broadcast.state == "ready" and (.broadcast.plugin_actions | any(.[]; .intent == "refresh_library"))' >/dev/null
jellyfin_items=$(get_json "$BASE/api/v1/broadcasts/$jellyfin_id/items"); printf '%s' "$jellyfin_items" | jq -e '.items | length == 1 and .[0].state == "ready"' >/dev/null
jellyfin_media_path=$(printf '%s' "$jellyfin_items" | jq -r '.items[0].published_path'); jellyfin_sha256=$(docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$jellyfin_media_path" | awk '{print $1}')
[ "$jellyfin_sha256" = "$SOURCE_SHA256" ] || { echo "Jellyfin media checksum mismatch: $jellyfin_sha256" >&2; exit 1; }
[ "$(request_count "$TMP/jellyfin/requests.jsonl")" -gt "$jellyfin_refresh_before" ] || { echo 'Jellyfin build did not refresh the remote library' >&2; exit 1; }; assert_request "$TMP/jellyfin/requests.jsonl" /Library/Refresh x-emby-token golden-media-server-token
jellyfin_action_refresh_before=$(request_count "$TMP/jellyfin/requests.jsonl")
jellyfin_action=$(post_json "$BASE/api/v1/broadcasts/$jellyfin_id/actions" '{"intent":"refresh_library"}'); jellyfin_action_job_id=$(printf '%s' "$jellyfin_action" | jq -r '.operation.id'); jellyfin_action_job=$(job_ready "$jellyfin_action_job_id"); printf '%s' "$jellyfin_action_job" | jq -e '.job.type == "jellyfin.broadcast.refresh_library" and .job.progress_label == "Broadcast operation complete"' >/dev/null
jellyfin_refresh_after=$(request_count "$TMP/jellyfin/requests.jsonl"); [ "$jellyfin_refresh_after" -gt "$jellyfin_action_refresh_before" ] || { echo 'Jellyfin queued refresh made no new remote request' >&2; exit 1; }; assert_request_since "$TMP/jellyfin/requests.jsonl" "$jellyfin_action_refresh_before" /Library/Refresh x-emby-token golden-media-server-token
touch "$TMP/jellyfin/refresh-fail"; jellyfin_failed_action=$(post_json "$BASE/api/v1/broadcasts/$jellyfin_id/actions" '{"intent":"refresh_library"}'); jellyfin_failed_job_id=$(printf '%s' "$jellyfin_failed_action" | jq -r '.operation.id'); jellyfin_failed_job=''; for _ in $(seq 1 90); do jellyfin_failed_job=$(get_json "$BASE/api/v1/jobs/$jellyfin_failed_job_id"); [ "$(printf '%s' "$jellyfin_failed_job" | jq -r '.job.state')" = failed ] && break; sleep 2; done; printf '%s' "$jellyfin_failed_job" | jq -e '.job.state == "failed" and .job.last_error != null' >/dev/null; docker compose "${COMPOSE_FILES[@]}" exec -T stashd test -f "$jellyfin_media_path"; jellyfin_failed_sha256=$(docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$jellyfin_media_path" | awk '{print $1}'); [ "$jellyfin_failed_sha256" = "$SOURCE_SHA256" ] || { echo "Jellyfin output changed after failed refresh: $jellyfin_failed_sha256" >&2; exit 1; }; rm "$TMP/jellyfin/refresh-fail"
jellyfin_recovered_action=$(post_json "$BASE/api/v1/broadcasts/$jellyfin_id/actions" '{"intent":"refresh_library"}'); job_ready "$(printf '%s' "$jellyfin_recovered_action" | jq -r '.operation.id')" >/dev/null
echo "jellyfin broadcast proof passed: media=$jellyfin_media_path sha256=$jellyfin_sha256"

# Plex connection, discovery, non-empty build, NFO publication, finalize, action, failure, recovery.
plex_connection=$(post_json "$BASE/api/v1/connections" '{"plugin_key":"plex","name":"Golden Plex","endpoint":"http://plex.test","token":"golden-media-server-token"}')
plex_connection_id=$(printf '%s' "$plex_connection" | jq -r '.connection.id')
plex_test=$(post_json "$BASE/api/v1/connections/$plex_connection_id/operations/test_connection" '{}'); printf 'plex test connection: %s\n' "$plex_test"; printf '%s' "$plex_test" | jq -e '.values | any(.[]; .key == "ok" and .value == "true")' >/dev/null
plex_libraries=$(post_json "$BASE/api/v1/connections/$plex_connection_id/operations/list_libraries" '{}'); printf 'plex libraries: %s\n' "$plex_libraries"; printf '%s' "$plex_libraries" | jq -e '.choices | any(.value == "fixture-library")' >/dev/null
assert_query "$TMP/plex/requests.jsonl" /identity X-Plex-Token golden-media-server-token
assert_query "$TMP/plex/requests.jsonl" /library/sections X-Plex-Token golden-media-server-token
plex_refresh_before=$(request_count "$TMP/plex/requests.jsonl")
plex_broadcast=$(post_json "$BASE/api/v1/stashes/$STASH_ID/broadcasts" '{"type":"plex","name":"Golden Plex","settings":{"media_server_connection_id":"'"$plex_connection_id"'","library_id":"fixture-library","captions":"off"}}')
plex_id=$(printf '%s' "$plex_broadcast" | jq -r '.broadcast.id'); plex_build_job_id=$(printf '%s' "$plex_broadcast" | jq -r '.build_job_id'); plex_build_job=$(job_ready "$plex_build_job_id"); printf 'plex build job: %s\n' "$plex_build_job"
plex_current=$(get_json "$BASE/api/v1/broadcasts/$plex_id"); printf '%s' "$plex_current" | jq -e '.broadcast.state == "ready" and (.broadcast.plugin_actions | any(.[]; .intent == "refresh_library"))' >/dev/null
plex_items=$(get_json "$BASE/api/v1/broadcasts/$plex_id/items"); printf '%s' "$plex_items" | jq -e '.items | length == 1 and .[0].state == "ready"' >/dev/null
plex_media_path=$(printf '%s' "$plex_items" | jq -r '.items[0].published_path'); plex_sha256=$(docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$plex_media_path" | awk '{print $1}'); [ "$plex_sha256" = "$SOURCE_SHA256" ] || { echo "Plex media checksum mismatch: $plex_sha256" >&2; exit 1; }
plex_nfo_path="${plex_media_path%/Season*}/tvshow.nfo"; docker compose "${COMPOSE_FILES[@]}" exec -T stashd test -s "$plex_nfo_path"; docker compose "${COMPOSE_FILES[@]}" exec -T stashd cat "$plex_nfo_path" > "$TMP/tvshow.nfo"
python3 - "$TMP/tvshow.nfo" <<'PY'
import sys
import xml.etree.ElementTree as ET
root = ET.parse(sys.argv[1]).getroot()
assert root.tag == 'tvshow'
assert root.findtext('title') == 'Stashd Library'
PY
plex_url=$(printf '%s' "$plex_current" | jq -r '.broadcast.published_url // empty'); [ -n "$plex_url" ] || { echo 'Plex broadcast did not publish tvshow.nfo' >&2; exit 1; }; curl -fsS "$plex_url" -H "Authorization: Bearer $TOKEN" > "$TMP/public.nfo"; cmp -s "$TMP/tvshow.nfo" "$TMP/public.nfo"
[ "$(request_count "$TMP/plex/requests.jsonl")" -gt "$plex_refresh_before" ] || exit 1; assert_query "$TMP/plex/requests.jsonl" /library/sections/fixture-library/refresh X-Plex-Token golden-media-server-token
plex_nfo_sha256=$(docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$plex_nfo_path" | awk '{print $1}')
plex_action_refresh_before=$(request_count "$TMP/plex/requests.jsonl")
plex_action=$(post_json "$BASE/api/v1/broadcasts/$plex_id/actions" '{"intent":"refresh_library"}'); plex_action_job_id=$(printf '%s' "$plex_action" | jq -r '.operation.id'); plex_action_job=$(job_ready "$plex_action_job_id"); printf '%s' "$plex_action_job" | jq -e '.job.type == "plex.broadcast.refresh_library" and .job.progress_label == "Broadcast operation complete"' >/dev/null
plex_refresh_after=$(request_count "$TMP/plex/requests.jsonl"); [ "$plex_refresh_after" -gt "$plex_action_refresh_before" ] || { echo 'Plex queued refresh made no new remote request' >&2; exit 1; }; assert_query_since "$TMP/plex/requests.jsonl" "$plex_action_refresh_before" /library/sections/fixture-library/refresh X-Plex-Token golden-media-server-token
touch "$TMP/plex/refresh-fail"; plex_failed_action=$(post_json "$BASE/api/v1/broadcasts/$plex_id/actions" '{"intent":"refresh_library"}'); plex_failed_job_id=$(printf '%s' "$plex_failed_action" | jq -r '.operation.id'); plex_failed_job=''; for _ in $(seq 1 90); do plex_failed_job=$(get_json "$BASE/api/v1/jobs/$plex_failed_job_id"); [ "$(printf '%s' "$plex_failed_job" | jq -r '.job.state')" = failed ] && break; sleep 2; done; printf '%s' "$plex_failed_job" | jq -e '.job.state == "failed" and .job.last_error != null' >/dev/null; docker compose "${COMPOSE_FILES[@]}" exec -T stashd test -f "$plex_media_path"; plex_failed_media_sha256=$(docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$plex_media_path" | awk '{print $1}'); [ "$plex_failed_media_sha256" = "$SOURCE_SHA256" ] || { echo "Plex media changed after failed refresh: $plex_failed_media_sha256" >&2; exit 1; }; docker compose "${COMPOSE_FILES[@]}" exec -T stashd test -f "$plex_nfo_path"; plex_failed_nfo_sha256=$(docker compose "${COMPOSE_FILES[@]}" exec -T stashd sha256sum "$plex_nfo_path" | awk '{print $1}'); [ "$plex_failed_nfo_sha256" = "$plex_nfo_sha256" ] || { echo "Plex NFO changed after failed refresh: $plex_failed_nfo_sha256" >&2; exit 1; }; rm "$TMP/plex/refresh-fail"
plex_recovered_action=$(post_json "$BASE/api/v1/broadcasts/$plex_id/actions" '{"intent":"refresh_library"}'); job_ready "$(printf '%s' "$plex_recovered_action" | jq -r '.operation.id')" >/dev/null
echo "plex broadcast proof passed: media=$plex_media_path nfo=$plex_nfo_path sha256=$plex_sha256 public=$plex_url"

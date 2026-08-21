#!/usr/bin/env bash
set -euo pipefail

export CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-1}"

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d "${TMPDIR:-/tmp}/stashd-youtube-input.XXXXXX")
host_pid=''
trap 'if [[ -n "$host_pid" ]]; then kill "$host_pid" 2>/dev/null || true; wait "$host_pid" 2>/dev/null || true; fi; rm -rf "$tmp"' EXIT

cargo build -p stashd-youtube-plugin --target wasm32-wasip2 --release
cargo build -p stashd-plugin-host --release

core="$root/target/wasm32-wasip2/release/stashd_youtube_plugin.wasm"
component="$tmp/youtube-input.component.wasm"
socket="$tmp/plugin-host.sock"
fixture_directory="$root/tests/fixtures/providers/youtube/http"
helper="$fixture_directory/../fake-yt-dlp.sh"
host="$root/target/release/stashd-plugin-host"

"$host" build-component "$core" "$component"
"$host" serve "$socket" 2>"$tmp/host.log" &
host_pid=$!
for _ in {1..50}; do [[ -S "$socket" ]] && break; sleep 0.1; done
if [[ ! -S "$socket" ]]; then
    cat "$tmp/host.log" >&2
    exit 1
fi

output=$(php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/@StashdDemo")
grep -q '"provider_input_id":"UCStashdDemoCh0012345678"' <<<"$output"
grep -q '"provider_item_id":"demoVideo01"' <<<"$output"
grep -q '"stage":"complete"' <<<"$output"
grep -q '"first_title":"Demo Episode One"' <<<"$output"

php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdBadFeed0123456789 malformed_feed
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdPrivateCh12345678 source_not_found
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdUnknownCh012345678 upstream_unavailable
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" resolve https://www.youtube.com/c/StashdUnresolvableChannel channel_resolution_failed

php "$root/scripts/youtube-data-api-parity.php" "$socket" "$component" "$fixture_directory"
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdDemoCh0012345678 credential_unavailable data-api
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdAuthFailCh0012345678 authentication_rejected data-api youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdRateLimitCh0123456789 rate_limited data-api youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdMalformedApi012345678 malformed_api_response data-api youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdMissingUploads12345678 source_not_found data-api youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/youtube-acquire-spike.php" "$socket" "$component" "$helper"
php "$root/scripts/youtube-acquire-parity.php" "$socket" "$component" "$helper"
php "$root/scripts/plugin-acquire-error.php" "$socket" "$component" "$helper" "https://www.youtube.com/watch?v=fail-acquisition" helper_failed

if php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/watch?v=demoVideo01"; then
    echo 'unsupported YouTube source unexpectedly succeeded' >&2
    exit 1
fi

echo 'YouTube input plugin end-to-end and parity check passed'

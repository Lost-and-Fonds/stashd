#!/usr/bin/env bash
set -euo pipefail

export CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-1}"

if command -v rg >/dev/null 2>&1; then
    generic_wit_leak=$(rg -n -i 'youtube|channel|rss|data-api|yt-dlp|feed' plugin-api/wit || true)
else
    generic_wit_leak=$(grep -REin 'youtube|channel|rss|data-api|yt-dlp|feed' plugin-api/wit || true)
fi
if [[ -n "$generic_wit_leak" ]]; then
    printf '%s\n' "$generic_wit_leak" >&2
    echo 'generic Input WIT contains provider-specific terminology' >&2
    exit 1
fi

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
grep -q '"id":"UCStashdDemoCh0012345678"' <<<"$output"
grep -q '"id":"demoVideo01"' <<<"$output"
grep -q '"stage":"complete"' <<<"$output"
grep -q '"title":"Demo Episode One"' <<<"$output"

php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/playlist?list=PLStashdDemoPlaylist01" >/dev/null
php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/c/StashdDemo" >/dev/null
php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/user/StashdDemo" >/dev/null
php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/watch?v=demoVideo01" >/dev/null
php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://youtu.be/demoVideo01" >/dev/null
php "$root/scripts/youtube-input-parity.php" "$socket" "$component" "$fixture_directory" "https://www.youtube.com/shorts/demoVideo01" >/dev/null

php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdBadFeed0123456789 invalid_data
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdPrivateCh12345678 not_found
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdUnknownCh012345678 unavailable
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" resolve https://www.youtube.com/c/StashdUnresolvableChannel invalid_data

php "$root/scripts/youtube-data-api-parity.php" "$socket" "$component" "$fixture_directory"
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdDemoCh0012345678 authentication complete
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdAuthFailCh0012345678 authentication complete youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdRateLimitCh0123456789 rate_limited complete youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdMalformedApi012345678 invalid_data complete youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/plugin-input-error.php" "$socket" "$component" "$fixture_directory" discover UCStashdMissingUploads12345678 not_found complete youtube-data-api fixture-secret-do-not-cross-wasm
php "$root/scripts/youtube-acquire-spike.php" "$socket" "$component" "$helper"
php "$root/scripts/youtube-acquire-parity.php" "$socket" "$component" "$helper"
php "$root/scripts/plugin-acquire-error.php" "$socket" "$component" "$helper" "https://www.youtube.com/watch?v=fail-acquisition" failed

echo 'YouTube input plugin end-to-end and parity check passed'

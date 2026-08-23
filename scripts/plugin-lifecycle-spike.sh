#!/usr/bin/env bash
set -euo pipefail

export CARGO_BUILD_JOBS="${CARGO_BUILD_JOBS:-1}"
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d "${TMPDIR:-/tmp}/stashd-plugin-lifecycle.XXXXXX")
host_pid=''
trap 'if [[ -n "$host_pid" ]]; then kill "$host_pid" 2>/dev/null || true; wait "$host_pid" 2>/dev/null || true; fi; rm -rf "$tmp"' EXIT

cargo build -p stashd-youtube-plugin --target wasm32-wasip2 --release
cargo build -p stashd-plugin-host --release

core="$root/target/wasm32-wasip2/release/stashd_youtube_plugin.wasm"
component="$tmp/youtube-input.component.wasm"
socket="$tmp/plugin-host.sock"
fixture_directory="$root/tests/fixtures/providers/youtube/http"
helper="$root/tests/fixtures/providers/youtube/fake-yt-dlp.sh"
host="$root/target/release/stashd-plugin-host"

"$host" build-component "$core" "$component"
"$host" serve "$socket" 2>"$tmp/host.log" &
host_pid=$!
for _ in {1..50}; do [[ -S "$socket" ]] && break; sleep 0.1; done
if [[ ! -S "$socket" ]]; then
    cat "$tmp/host.log" >&2
    exit 1
fi

export STASHD_PLUGIN_COMPONENT="$component"
export STASHD_PLUGIN_HOST_SOCKET="$socket"
export STASHD_PLUGIN_FIXTURE_DIR="$fixture_directory"
export STASHD_PLUGIN_HELPER_EXECUTABLE="$helper"
export YOUTUBE_DATA_API_KEY='test-api-key'

ENVIRONMENT="${ENVIRONMENT:-testing}" \
DB_CONNECTION="${DB_CONNECTION:-pgsql}" \
DB_HOST="${DB_HOST:-127.0.0.1}" \
DB_PORT="${DB_PORT:-5432}" \
DB_DATABASE="${DB_DATABASE:-stashd}" \
DB_USERNAME="${DB_USERNAME:-postgres}" \
DB_PASSWORD="${DB_PASSWORD:-}" \
vendor/bin/pest tests/Feature/ExternalInputPluginLifecycleTest.php --no-progress
echo 'external Input plugin lifecycle check passed'

#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d)
host_pid=''
trap 'if [[ -n "$host_pid" ]]; then kill "$host_pid" 2>/dev/null || true; wait "$host_pid" 2>/dev/null || true; fi; rm -rf "$tmp"' EXIT

cargo build -p stashd-example-plugin --target wasm32-wasip2 --release
cargo build -p stashd-plugin-host --release

core="$root/target/wasm32-wasip2/release/stashd_example_plugin.wasm"
component="$tmp/example.component.wasm"
socket="$tmp/plugin-host.sock"
asset="$root/plugins/example/fixtures/asset.txt"
staging="$tmp/staging-output.txt"
host="$root/target/release/stashd-plugin-host"

"$host" build-component "$core" "$component"
"$host" serve "$socket" 2>"$tmp/host.log" &
host_pid=$!
for _ in {1..50}; do [[ -S "$socket" ]] && break; sleep 0.1; done

copy_output=$(php "$root/scripts/plugin-spike-php.php" "$socket" "$component" "$asset" "$staging" copy)
cmp "$asset" "$staging"
grep -q '"source_bytes":' <<<"$copy_output"
grep -q 'example plugin received its invocation grant' <<<"$copy_output"
grep -q '"fraction":1' <<<"$copy_output"

if php "$root/scripts/plugin-spike-php.php" "$socket" "$component" "$asset" "$tmp/typed-failure" typed-failure; then
    echo 'typed plugin failure unexpectedly succeeded' >&2
    exit 1
fi
if php "$root/scripts/plugin-spike-php.php" "$socket" "$component" "$asset" "$tmp/trap" trap; then
    echo 'trapping plugin unexpectedly succeeded' >&2
    exit 1
fi

printf 'not a component' > "$tmp/invalid.wasm"
if php "$root/scripts/plugin-spike-php.php" "$socket" "$tmp/invalid.wasm" "$asset" "$tmp/invalid-output" copy; then
    echo 'invalid component unexpectedly loaded' >&2
    exit 1
fi

php "$root/scripts/plugin-spike-php.php" "$socket" "$component" "$asset" "$tmp/recovery-output" copy >/dev/null
cmp "$asset" "$tmp/recovery-output"
echo 'plugin architecture spike end-to-end check passed'

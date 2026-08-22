#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RUNNER="$ROOT/runner.php"
PLUGIN="$ROOT/plugin"
STAGING="$ROOT/jobs"

rm -rf "$STAGING"
mkdir -p "$STAGING"
output=$(php "$RUNNER" --staging-root "$STAGING" --timeout 5 "$PLUGIN" plugin.php)

printf '%s\n' "$output" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$report = $result["report"] ?? [];
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); };
$assert = static function (bool $condition, string $message) use ($fail): void { if (! $condition) { $fail($message); } };
$assert(($result["exit_code"] ?? null) === 0, "plugin did not exit successfully");
$assert(($result["timed_out"] ?? true) === false, "normal plugin timed out");
$assert(($result["staging_clean"] ?? false) === true, "job staging was not cleaned");
$assert(($result["report_observed_before_cleanup"] ?? false) === true, "staging report was not observed");
$assert(($report["vault"] ?? null) === false, "Vault was visible");
$assert(($report["outer_app"] ?? null) === false, "outer application filesystem was visible");
$assert(($report["outer_data"] ?? null) === false, "outer data filesystem was visible");
$assert(($report["proc"] ?? true) === false, "/proc was visible");
$assert(($report["database_env"] ?? null) === null && ($report["encryption_env"] ?? null) === null, "sensitive environment leaked");
$assert(($report["plugin_mutation"] ?? true) === false, "plugin package was writable");
$assert(($report["staging_write"] ?? false) === true, "staging was not writable");
$assert(($report["tmp_write"] ?? false) === true, "private /tmp was not writable");
$assert(($report["direct_network"] ?? true) === false, "direct network succeeded");
echo "M1 sandbox isolation: PASS\n";
'
[ ! -e "$PLUGIN/MUTATION_TEST" ] || { echo "FAIL: package mutation escaped sandbox" >&2; exit 1; }

set +e
timeout_output=$(php "$RUNNER" --staging-root "$STAGING" --timeout 0.2 "$PLUGIN" hang.php)
timeout_status=$?
set -e
[ "$timeout_status" -eq 124 ] || { echo "FAIL: timeout exit status was $timeout_status" >&2; exit 1; }
printf '%s\n' "$timeout_output" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($result["timed_out"] ?? false) !== true || ($result["staging_clean"] ?? false) !== true) {
    fwrite(STDERR, "FAIL: timeout did not terminate and clean staging\n");
    exit(1);
}
echo "M1 timeout cleanup: PASS\n";
'
[ -z "$(find "$STAGING" -mindepth 1 -print -quit)" ] || { echo "FAIL: timeout left staging files" >&2; exit 1; }

set +e
php "$RUNNER" --staging-root "$STAGING" --timeout 1 "$PLUGIN" ../plugin.php >/dev/null 2>&1
path_status=$?
set -e
[ "$path_status" -eq 2 ] || { echo "FAIL: unsafe entrypoint path was accepted" >&2; exit 1; }
echo "M1 package-path validation: PASS"

rm -rf "$STAGING"
mkdir -p "$STAGING"
parent_output=$(sh -c '
    php "$1" --staging-root "$2" --timeout 10 "$3" hang.php > "$2/runner.json" 2> "$2/runner.err" &
    runner=$!
    sleep 0.5
    kill -TERM "$runner"
    wait "$runner" || true
    sleep 1
    if find "$2" -name alive -print -quit | grep -q .; then
        echo parent-death-failed
        exit 1
    fi
    echo parent-death-pass
' sh "$RUNNER" "$STAGING" "$PLUGIN")
[ "$parent_output" = parent-death-pass ] || { echo "FAIL: $parent_output" >&2; exit 1; }
echo "M1 parent death: PASS"
echo "M1 native runner skeleton: PASS"

#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

php "$ROOT/php-client.php"
python3 "$ROOT/python-client.py"
php "$ROOT/protocol-failures.php"

normal=$(php "$ROOT/runner.php" normal)
printf '%s\n' "$normal" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($result["response"]["result"]["echo"] ?? null) !== "host-response") {
    fwrite(STDERR, "FAIL: bidirectional host call failed\n");
    exit(1);
}
if (! in_array("log", $result["notifications"] ?? [], true) || ! in_array("progress", $result["notifications"] ?? [], true)) {
    fwrite(STDERR, "FAIL: notifications were not delivered\n");
    exit(1);
}
if (! str_contains($result["stderr"] ?? "", "fixture stderr log")) {
    fwrite(STDERR, "FAIL: stderr was not kept separate\n");
    exit(1);
}
echo "M2 native PHP RPC fixture: PASS\n";
'

cancel=$(php "$ROOT/runner.php" cancel)
printf '%s\n' "$cancel" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($result["response"]["error"]["code"] ?? null) !== "cancelled") {
    fwrite(STDERR, "FAIL: cooperative cancellation failed\n");
    exit(1);
}
echo "M2 cancellation: PASS\n";
'

mismatch=$(php "$ROOT/runner.php" mismatch)
printf '%s\n' "$mismatch" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($result["negotiation"] ?? null) !== "rejected" || ($result["response"]["error"]["code"] ?? null) !== "protocol-mismatch") {
    fwrite(STDERR, "FAIL: incompatible protocol was not rejected\n");
    exit(1);
}
echo "M2 version negotiation: PASS\n";
'

set +e
timeout_result=$(php "$ROOT/runner.php" hang)
timeout_status=$?
set -e
[ "$timeout_status" -eq 124 ] || { echo "FAIL: hard timeout status was $timeout_status" >&2; exit 1; }
printf '%s\n' "$timeout_result" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($result["timed_out"] ?? false) !== true) {
    fwrite(STDERR, "FAIL: hard timeout was not reported\n");
    exit(1);
}
echo "M2 timeout: PASS\n";
'

for mode in malformed crash; do
    result=$(php "$ROOT/runner.php" "$mode")
    printf '%s\n' "$result" | php -r '
$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! is_string($result["error"] ?? null)) {
    fwrite(STDERR, "FAIL: malformed/EOF failure was not classified\n");
    exit(1);
}
'
done
echo "M2 malformed-frame/EOF/crash handling: PASS"
echo "M2 RPC v1 fixture: PASS"

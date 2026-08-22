#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)

"$ROOT/spikes/native-plugin-runner/m3/run.sh"
php -d zend.assertions=1 -d assert.exception=1 "$ROOT/spikes/native-plugin-runner/m4/test_m4.php"
"$ROOT/spikes/native-plugin-runner/test.sh"

#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
M3="$ROOT/spikes/native-plugin-runner/m3"

python3 "$M3/extract_wit.py" --repo-root "$ROOT" --output-dir "$M3/generated"
PYTHONPATH="$M3" python3 "$M3/test_m3.py" \
    --repo-root "$ROOT" \
    --schema "$M3/generated/wit-schema.json" \
    --goldens "$M3/goldens"

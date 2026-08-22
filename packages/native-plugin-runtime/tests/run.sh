#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
IMAGE=${NATIVE_RUNTIME_TEST_IMAGE:-stashd-native-runtime-tests:local}
CANARY=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
"$ROOT/packages/plugin-sdk/tests/run.sh"
# M1-M3 remain historical protocol/sandbox evidence; the assembled M4-M7
# behavior below runs against the productionized packages.
"$ROOT/spikes/native-plugin-runner/test.sh"
"$ROOT/spikes/native-plugin-runner/m3/run.sh"
podman build --quiet -f "$ROOT/packages/native-plugin-runtime/tests/Dockerfile" -t "$IMAGE" "$ROOT/packages" >/dev/null
podman run --rm \
    --user 1000:1000 \
    --cap-drop=ALL \
    --security-opt=no-new-privileges \
    -e M7_VAULT_CANARY="$CANARY" \
    "$IMAGE"

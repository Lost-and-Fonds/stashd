#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)

"$ROOT/spikes/native-plugin-runner/m3/run.sh"
php -d zend.assertions=1 -d assert.exception=1 "$ROOT/spikes/native-plugin-runner/m4/test_m4.php"

IMAGE=${M6_RUNNER_IMAGE:-stashd-native-runner-m6:local}
CANARY=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
podman build --quiet -f "$ROOT/spikes/native-plugin-runner/m6/Dockerfile" -t "$IMAGE" "$ROOT/spikes/native-plugin-runner" >/dev/null
podman run --rm \
    --user 1000:1000 \
    --cap-drop=ALL \
    --security-opt=no-new-privileges \
    -e M6_VAULT_CANARY="$CANARY" \
    "$IMAGE"

"$ROOT/spikes/native-plugin-runner/m5/run.sh"

#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
IMAGE=${M7_RUNNER_IMAGE:-stashd-native-runner-m7:local}
CANARY=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')

php -d zend.assertions=1 -d assert.exception=1 "$ROOT/spikes/native-plugin-runner/m7/postgres.php"
podman build --quiet -f "$ROOT/spikes/native-plugin-runner/m7/Dockerfile" -t "$IMAGE" "$ROOT/spikes/native-plugin-runner" >/dev/null
podman run --rm \
    --user 1000:1000 \
    --cap-drop=ALL \
    --security-opt=no-new-privileges \
    -e M7_VAULT_CANARY="$CANARY" \
    "$IMAGE"

"$ROOT/spikes/native-plugin-runner/m6/run.sh"

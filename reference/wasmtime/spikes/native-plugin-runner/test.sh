#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
IMAGE=${M1_RUNNER_IMAGE:-stashd-native-runner-m1:local}
CANARY=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')

podman build --quiet -t "$IMAGE" "$ROOT" >/dev/null
podman run --rm \
    --user 1000:1000 \
    --cap-drop=ALL \
    --security-opt=no-new-privileges \
    -e M1_VAULT_CANARY="$CANARY" \
    "$IMAGE"

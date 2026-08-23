#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
IMAGE=${PLUGIN_RUNTIME_TEST_IMAGE:-stashd-plugin-runtime-tests:local}
CANARY=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
if command -v podman >/dev/null 2>&1; then
    CONTAINER_ENGINE=podman
elif command -v docker >/dev/null 2>&1; then
    CONTAINER_ENGINE=docker
else
    echo 'plugin runtime conformance requires podman or docker' >&2
    exit 1
fi

"$CONTAINER_ENGINE" build --quiet -f "$ROOT/packages/plugin-runtime/tests/Dockerfile" -t "$IMAGE" "$ROOT" >/dev/null
"$CONTAINER_ENGINE" run --rm \
    --user 1000:1000 \
    --cap-drop=ALL \
    --security-opt=no-new-privileges \
    -e M7_VAULT_CANARY="$CANARY" \
    "$IMAGE"

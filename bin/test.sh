#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# The opt-in live proof needs host Chromium and restarts PHP; never run it in parallel.
if [[ "${1:-}" == "--mercure-spike" ]]; then
    [[ "$#" == 1 ]] || { echo "--mercure-spike accepts no additional arguments" >&2; exit 2; }
    node dev/mercure-spike/verify.mjs
    exec node dev/mercure-spike/load.mjs
fi
processes="${STASHD_TEST_PROCESSES:-4}"
if [[ "$processes" != "1" ]]; then
    set -- --parallel "--processes=$processes" "$@"
fi
if [[ -n "${CI:-}" ]]; then
    ENVIRONMENT=testing exec php vendor/bin/pest "$@"
fi
exec lerd composer test -- "$@"

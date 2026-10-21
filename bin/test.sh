#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
processes="${STASHD_TEST_PROCESSES:-4}"
if [[ "$processes" != "1" ]]; then
    set -- --parallel "--processes=$processes" "$@"
fi
if [[ -n "${CI:-}" ]]; then
    ENVIRONMENT=testing exec php vendor/bin/pest "$@"
fi
exec lerd composer test -- "$@"

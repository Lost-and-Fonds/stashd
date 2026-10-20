#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -n "${CI:-}" ]]; then
    ENVIRONMENT=testing exec php vendor/bin/pest "$@"
fi
exec lerd composer test -- "$@"

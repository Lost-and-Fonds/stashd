#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PACKAGE_ROOT=${STASHD_PLUGIN_PACKAGE_ROOT:-$(mktemp -d)}
CREATED_ROOT=0

if [ "${STASHD_PLUGIN_PACKAGE_ROOT:-}" = "" ]; then
    CREATED_ROOT=1
fi

cleanup() {
    if [ "$CREATED_ROOT" -eq 1 ]; then
        rm -rf "$PACKAGE_ROOT"
    fi
}
trap cleanup EXIT INT TERM

mkdir -p "$PACKAGE_ROOT"
export STASHD_PLUGIN_PACKAGE_ROOT="$PACKAGE_ROOT"

php "$ROOT/scripts/build-installed-plugins.php"
"$ROOT/scripts/test-postgres.sh" \
    "$ROOT/tests/Feature/PodcastPluginLifecycleTest.php" \
    "$ROOT/tests/Feature/ExternalInputPluginLifecycleTest.php"

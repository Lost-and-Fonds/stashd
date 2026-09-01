#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PACKAGE_ROOT=$(mktemp -d)
CREATED_ROOT=1

cleanup() {
    if [ "$CREATED_ROOT" -eq 1 ]; then
        rm -rf "$PACKAGE_ROOT"
    fi
}
trap cleanup EXIT INT TERM

rm -rf "$PACKAGE_ROOT"
mkdir -p "$PACKAGE_ROOT"
export STASHD_PLUGIN_PACKAGE_ROOT="$PACKAGE_ROOT"

restore() {
    (cd "$ROOT" && composer install --no-interaction --prefer-dist --no-progress >/dev/null)
}
trap 'restore; cleanup' EXIT INT TERM

(cd "$ROOT" && composer install --no-interaction --prefer-dist --no-progress)

for package in jellyfin plex podcast youtube php-sdk plugin-api; do
    rm -rf "$ROOT/vendor/stashd/$package"
done

for package in jellyfin plex podcast youtube php-sdk plugin-api; do
    if [ -e "$ROOT/vendor/stashd/$package" ]; then
        echo "provider package remains installed: stashd/$package" >&2
        exit 1
    fi
done

STASHD_NAKED_CORE=1 "$ROOT/scripts/test-postgres.sh" "$ROOT/tests/Feature/NakedCoreLifecycleTest.php"

#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

restore() {
    (cd "$ROOT" && composer install --no-interaction --prefer-dist --no-progress >/dev/null)
}
trap restore EXIT INT TERM

(cd "$ROOT" && composer install --no-dev --no-interaction --prefer-dist --no-progress)

for package in jellyfin plex podcast youtube plugin-sdk plugin-api; do
    if [ -e "$ROOT/vendor/stashd/$package" ]; then
        echo "provider package remains installed: stashd/$package" >&2
        exit 1
    fi
done

STASHD_NAKED_CORE=1 "$ROOT/scripts/test-postgres.sh" "$ROOT/tests/Feature/NakedCoreLifecycleTest.php"

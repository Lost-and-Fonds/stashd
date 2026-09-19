#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
IMAGE="${STASHD_APPARMOR_IMAGE:-stashd:golden}"
COMPOSE_PROJECT_NAME="stashd-apparmor-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME STASHD_IMAGE="$IMAGE"
export STASHD_HOST_PORT="${STASHD_APPARMOR_PORT:-18479}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
export SIGNING_KEY="${STASHD_APPARMOR_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"
export PUID="${STASHD_APPARMOR_PUID:-1000}"
export PGID="${STASHD_APPARMOR_PGID:-1000}"

TMP=$(mktemp -d)
PROFILE="$ROOT/deploy/apparmor/stashd-plugin-bwrap"
INSTALLER="$ROOT/deploy/apparmor/install-stashd-plugin-bwrap.sh"
APPARMOR_LOG="$TMP/apparmor.log"
DEFAULT_COMPOSE_FILES=(-f "$ROOT/docker-compose.yml")
COMPOSE_FILES=(-f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose.apparmor.yml")

if [ "$(id -u)" -eq 0 ]; then SUDO=(); else SUDO=(sudo); fi
as_root() { "${SUDO[@]}" "$@"; }

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        echo '--- AppArmor probe output ---' >&2
        cat "$APPARMOR_LOG" >&2 2>/dev/null || true
        docker compose "${DEFAULT_COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true
        docker compose "${COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true
    fi
    docker compose "${DEFAULT_COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

command -v docker >/dev/null 2>&1 || { echo 'docker is required' >&2; exit 1; }
command -v aa-status >/dev/null 2>&1 || { echo 'aa-status is required' >&2; exit 1; }
command -v apparmor_parser >/dev/null 2>&1 || { echo 'apparmor_parser is required' >&2; exit 1; }

cat /etc/os-release >&2
uname -a >&2
aa-status >&2
apparmor_parser --version >&2
sysctl kernel.apparmor_restrict_unprivileged_userns kernel.unprivileged_userns_clone >&2 2>&1 || true
command -v bwrap >&2 && bwrap --version >&2 || true
docker version >&2
docker info --format '{{json .SecurityOptions}}' >&2
docker run --rm --entrypoint sh "$IMAGE" -lc 'bwrap --version; command -v bwrap' >&2

if ! aa-status --enabled >/dev/null 2>&1; then
    echo 'authoritative AppArmor proof refused: AppArmor is not active' >&2
    exit 1
fi

if [ -r /proc/sys/kernel/apparmor_restrict_unprivileged_userns ]; then
    [ "$(cat /proc/sys/kernel/apparmor_restrict_unprivileged_userns)" = 1 ] || {
        echo 'authoritative AppArmor proof refused: kernel.apparmor_restrict_unprivileged_userns is not 1' >&2
        exit 1
    }
fi

run_bwrap() {
    local apparmor_profile=$1

    docker run --rm --user "$PUID:$PGID" \
        --security-opt seccomp=unconfined \
        --security-opt "apparmor=$apparmor_profile" \
        --entrypoint sh "$IMAGE" -lc '
            exec bwrap --die-with-parent --new-session \
                --unshare-user --unshare-pid --unshare-net --unshare-ipc --unshare-uts \
                --clearenv --ro-bind /usr/local /usr/local --ro-bind /usr /usr \
                --ro-bind /bin /bin --ro-bind /lib /lib --ro-bind /lib64 /lib64 \
                --ro-bind /sbin /sbin --tmpfs /tmp --dev /dev --dir /home \
                --dir /root --dir /run --chdir / --setenv HOME /tmp \
                --setenv PATH /usr/local/bin:/usr/bin:/bin -- /usr/bin/true
        '
}

wait_for_stashd() {
    local compose_args=("$@")
    local stashd_container
    local boot_log=''
    local health_status=''

    stashd_container=$(docker compose "${compose_args[@]}" ps -q stashd)
    for _ in $(seq 1 180); do
        boot_log=$(timeout 5s docker logs --since 15m "$stashd_container" 2>/dev/null || true)
        health_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$stashd_container" 2>/dev/null || true)
        if printf '%s' "$boot_log" | grep -q 'Stashd boot completed.' || [ "$health_status" = healthy ]; then
            return 0
        fi
        sleep 2
    done

    printf '%s\n' "$boot_log" >&2
    docker compose "${compose_args[@]}" logs stashd >&2 || true
    return 1
}

run_boundary() {
    local compose_args=("$@")

    docker compose "${compose_args[@]}" cp \
        "$ROOT/tests/docker/plugin-sandbox-boundary.php" stashd:/tmp/plugin-sandbox-boundary.php
    timeout 60s docker compose "${compose_args[@]}" exec -T --user "$PUID:$PGID" stashd \
        php /tmp/plugin-sandbox-boundary.php
}

echo '--- stock docker-default bwrap probe ---' | tee -a "$APPARMOR_LOG"
audit_started=$(date --iso-8601=seconds)
before_dmesg="$TMP/dmesg.before"
after_dmesg="$TMP/dmesg.after"
as_root dmesg -T >"$before_dmesg" 2>/dev/null || true
set +e
stock_output=$(run_bwrap docker-default 2>&1)
stock_status=$?
set -e
printf 'exit=%s\n%s\n' "$stock_status" "$stock_output" | tee -a "$APPARMOR_LOG"
[ "$stock_status" -ne 0 ] || { echo 'docker-default unexpectedly allowed bwrap' >&2; exit 1; }
as_root dmesg -T >"$after_dmesg" 2>/dev/null || true
apparmor_denial=$(grep -E 'apparmor="DENIED".*(profile="docker-default"|comm="bwrap"|operation="(mount|userns_create)")' "$after_dmesg" | tail -20 || true)
if [ -z "$apparmor_denial" ] && command -v journalctl >/dev/null 2>&1; then
    apparmor_denial=$(as_root journalctl -k --since "$audit_started" --no-pager 2>/dev/null | grep -E 'apparmor="DENIED".*(profile="docker-default"|comm="bwrap"|operation="(mount|userns_create)")' | tail -20 || true)
fi
printf '%s\n' "$apparmor_denial" | tee -a "$APPARMOR_LOG"
[ -n "$apparmor_denial" ] || { echo 'no corresponding docker-default AppArmor audit denial was captured' >&2; exit 1; }

profile_probe_name="stashd-apparmor-profile-probe-${RANDOM}"
docker run -d --name "$profile_probe_name" --security-opt apparmor=docker-default \
    --entrypoint sh "$IMAGE" -lc 'sleep 30' >/dev/null
docker inspect --format 'container AppArmor profile: {{.AppArmorProfile}}' "$profile_probe_name" | tee -a "$APPARMOR_LOG"
docker rm -f "$profile_probe_name" >/dev/null

echo '--- stock docker-default real plugin boundary ---' | tee -a "$APPARMOR_LOG"
export STASHD_DATA_DIR="$TMP/stock-data" STASHD_MEDIA_DIR="$TMP/stock-media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
docker compose "${DEFAULT_COMPOSE_FILES[@]}" up -d
wait_for_stashd "${DEFAULT_COMPOSE_FILES[@]}"
set +e
stock_boundary_output=$(run_boundary "${DEFAULT_COMPOSE_FILES[@]}" 2>&1)
stock_boundary_status=$?
set -e
printf 'exit=%s\n%s\n' "$stock_boundary_status" "$stock_boundary_output" | tee -a "$APPARMOR_LOG"
[ "$stock_boundary_status" -ne 0 ] || { echo 'docker-default unexpectedly allowed the real plugin boundary' >&2; exit 1; }
docker compose "${DEFAULT_COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null

echo '--- install Stashd profile ---' | tee -a "$APPARMOR_LOG"
as_root "$INSTALLER" --require | tee -a "$APPARMOR_LOG"

echo '--- normal Core process restrictions ---' | tee -a "$APPARMOR_LOG"
docker run --rm --user "$PUID:$PGID" --security-opt seccomp=unconfined \
    --security-opt apparmor=stashd-plugin-bwrap --entrypoint sh "$IMAGE" -lc '
        mkdir -p /tmp/stashd-mount-test
        if mount -t tmpfs tmpfs /tmp/stashd-mount-test 2>/dev/null; then exit 1; fi
        php -r '\''preg_match("/^CapEff:\\s*([0-9a-f]+)/m", file_get_contents("/proc/self/status"), $m); exit(((int) hexdec($m[1] ?? "0") & (1 << 21)) === 0 ? 0 : 1);'\''
    '

echo '--- Stashd profile bwrap probe ---' | tee -a "$APPARMOR_LOG"
run_bwrap stashd-plugin-bwrap | tee -a "$APPARMOR_LOG"

echo '--- remove userns permission ---' | tee -a "$APPARMOR_LOG"
negative_profile="$TMP/stashd-plugin-bwrap-no-userns"
sed '/^[[:space:]]*userns,$/d' "$PROFILE" >"$negative_profile"
as_root apparmor_parser -r -W "$negative_profile"
set +e
negative_output=$(run_bwrap stashd-plugin-bwrap 2>&1)
negative_status=$?
set -e
printf 'exit=%s\n%s\n' "$negative_status" "$negative_output" | tee -a "$APPARMOR_LOG"
[ "$negative_status" -ne 0 ] || { echo 'profile without userns unexpectedly allowed bwrap' >&2; exit 1; }

echo '--- profile without userns real plugin boundary ---' | tee -a "$APPARMOR_LOG"
export STASHD_DATA_DIR="$TMP/negative-data" STASHD_MEDIA_DIR="$TMP/negative-media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
docker compose "${COMPOSE_FILES[@]}" up -d
wait_for_stashd "${COMPOSE_FILES[@]}"
set +e
negative_boundary_output=$(run_boundary "${COMPOSE_FILES[@]}" 2>&1)
negative_boundary_status=$?
set -e
printf 'exit=%s\n%s\n' "$negative_boundary_status" "$negative_boundary_output" | tee -a "$APPARMOR_LOG"
[ "$negative_boundary_status" -ne 0 ] || { echo 'profile without userns unexpectedly allowed the real plugin boundary' >&2; exit 1; }
docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null
as_root "$INSTALLER" --require | tee -a "$APPARMOR_LOG"

export STASHD_DATA_DIR="$TMP/data" STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
docker compose "${COMPOSE_FILES[@]}" up -d
wait_for_stashd "${COMPOSE_FILES[@]}"
run_boundary "${COMPOSE_FILES[@]}"

echo 'authoritative AppArmor plugin sandbox proof passed' | tee -a "$APPARMOR_LOG"

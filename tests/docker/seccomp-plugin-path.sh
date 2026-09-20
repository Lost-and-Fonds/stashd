#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
IMAGE="${STASHD_SECCOMP_IMAGE:-stashd:seccomp}"
COMPOSE_PROJECT_NAME="stashd-seccomp-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME STASHD_IMAGE="$IMAGE"
export STASHD_HOST_PORT="${STASHD_SECCOMP_PORT:-18481}"
export STASHD_PUBLIC_URL="http://127.0.0.1:${STASHD_HOST_PORT}"
export SIGNING_KEY="${STASHD_SECCOMP_SIGNING_KEY:-MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=}"
export PUID="${STASHD_SECCOMP_PUID:-1000}" PGID="${STASHD_SECCOMP_PGID:-1000}"

PROFILE="$ROOT/deploy/apparmor/stashd-plugin-bwrap"
INSTALLER="$ROOT/deploy/apparmor/install-stashd-plugin-bwrap.sh"
SECCOMP_PROFILE="$ROOT/deploy/seccomp/stashd-plugin-bwrap.json"
COMPOSE_FILES=(-f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose.apparmor.yml")
TMP=$(mktemp -d)

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        docker compose "${COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true
        docker compose "${COMPOSE_FILES[@]}" ps >&2 2>/dev/null || true
    fi
    docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP"
    exit "$status"
}
trap cleanup EXIT INT TERM

command -v docker >/dev/null 2>&1 || { echo 'docker is required' >&2; exit 1; }
command -v aa-status >/dev/null 2>&1 || { echo 'aa-status is required' >&2; exit 1; }
command -v apparmor_parser >/dev/null 2>&1 || { echo 'apparmor_parser is required' >&2; exit 1; }
as_root() { [ "$(id -u)" -eq 0 ] && "$@" || sudo "$@"; }
as_root aa-status --enabled >/dev/null 2>&1 || { echo 'seccomp proof requires enforcing AppArmor' >&2; exit 1; }
as_root "$INSTALLER" --require >/dev/null

cat /etc/os-release >&2
uname -a >&2
docker version >&2
docker info --format '{{json .SecurityOptions}}' >&2
as_root aa-status >&2
apparmor_parser --version >&2
as_root sysctl kernel.apparmor_restrict_unprivileged_userns kernel.unprivileged_userns_clone >&2 2>&1 || true
docker run --rm --entrypoint sh "$IMAGE" -lc 'bwrap --version' >&2

bwrap_args='bwrap --die-with-parent --new-session \
    --unshare-user --unshare-pid --unshare-net --unshare-ipc --unshare-uts \
    --clearenv --ro-bind /usr/local /usr/local --ro-bind /usr /usr \
    --ro-bind /bin /bin --ro-bind /lib /lib --ro-bind /lib64 /lib64 \
    --ro-bind /sbin /sbin --tmpfs /tmp --dev /dev --dir /home \
    --dir /root --dir /run --chdir / --setenv HOME /tmp \
    --setenv PATH /usr/local/bin:/usr/bin:/bin -- /usr/bin/true'

run_probe() {
    local seccomp=$1
    local seccomp_args=()
    [ "$seccomp" = default ] || seccomp_args+=(--security-opt "seccomp=$seccomp")

    docker run --rm --user "$PUID:$PGID" \
        "${seccomp_args[@]}" \
        --security-opt apparmor=stashd-plugin-bwrap \
        --entrypoint sh "$IMAGE" -lc "exec $bwrap_args"
}

echo '--- Docker default seccomp: minimal bwrap must fail ---'
set +e
default_output=$(run_probe default 2>&1)
default_status=$?
set -e
printf 'exit=%s\n%s\n' "$default_status" "$default_output"
[ "$default_status" -ne 0 ] || { echo 'default seccomp unexpectedly allowed bwrap' >&2; exit 1; }
printf '%s\n' "$default_output" | grep -F 'No permissions to create new namespace' >/dev/null || {
    echo 'default seccomp failure did not produce the expected bwrap namespace error' >&2
    exit 1
}

echo '--- unconfined investigation trace: identify the actual clone flags ---'
docker run --rm --security-opt seccomp=unconfined --security-opt apparmor=stashd-plugin-bwrap \
    --entrypoint sh "$IMAGE" -lc "apt-get update -qq && apt-get install -y -qq strace >/dev/null && gosu $PUID:$PGID strace -f -e trace=clone,clone3,unshare,setns,mount,umount2,pivot_root $bwrap_args" 2>&1 | tee "$TMP/trace.log"
grep -E 'clone\(.*CLONE_NEWNS.*CLONE_NEWUTS.*CLONE_NEWIPC.*CLONE_NEWUSER.*CLONE_NEWPID.*CLONE_NEWNET' "$TMP/trace.log" >/dev/null || {
    echo 'investigation trace did not capture the expected bubblewrap namespace clone' >&2
    exit 1
}

echo '--- candidate seccomp: minimal bwrap must pass ---'
run_probe "$SECCOMP_PROFILE"

echo '--- candidate seccomp: unrelated namespace flag form remains denied ---'
set +e
reduced_output=$(docker run --rm --user "$PUID:$PGID" --security-opt "seccomp=$SECCOMP_PROFILE" \
    --security-opt apparmor=stashd-plugin-bwrap --entrypoint sh "$IMAGE" -lc \
    'bwrap --die-with-parent --new-session --unshare-user --unshare-pid --clearenv --ro-bind /usr /usr --tmpfs /tmp --dev /dev --chdir / -- /usr/bin/true' 2>&1)
reduced_status=$?
set -e
printf 'exit=%s\n%s\n' "$reduced_status" "$reduced_output"
[ "$reduced_status" -ne 0 ] || { echo 'candidate profile unexpectedly allowed a non-Stashd namespace flag form' >&2; exit 1; }

echo '--- candidate seccomp: Core remains unprivileged ---'
core_output=$(docker run --rm --user "$PUID:$PGID" \
    --security-opt "seccomp=$SECCOMP_PROFILE" \
    --security-opt apparmor=stashd-plugin-bwrap --entrypoint sh "$IMAGE" -lc '
        mkdir -p /tmp/stashd-mount-test
        mount -t tmpfs tmpfs /tmp/stashd-mount-test 2>/dev/null && exit 1
        php -r '\''preg_match("/^CapEff:\\s*([0-9a-f]+)/m", file_get_contents("/proc/self/status"), $m); exit(((int) hexdec($m[1] ?? "0") & (1 << 21)) === 0 ? 0 : 1);'\''
    ' 2>&1)
printf '%s\n' "$core_output"

wait_for_stashd() {
    local compose_args=("$@")
    local container
    container=$(docker compose "${compose_args[@]}" ps -q stashd)
    for _ in $(seq 1 180); do
        [ "$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$container" 2>/dev/null || true)" = healthy ] && return 0
        sleep 2
    done
    docker compose "${compose_args[@]}" logs stashd >&2 || true
    return 1
}

run_boundary() {
    local compose_args=("$@")
    docker compose "${compose_args[@]}" cp "$ROOT/tests/docker/plugin-sandbox-boundary.php" stashd:/tmp/plugin-sandbox-boundary.php
    timeout 60s docker compose "${compose_args[@]}" exec -T --user "$PUID:$PGID" stashd php /tmp/plugin-sandbox-boundary.php
}

echo '--- candidate seccomp: real PluginRunner boundary ---'
export STASHD_DATA_DIR="$TMP/data" STASHD_MEDIA_DIR="$TMP/media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
docker compose "${COMPOSE_FILES[@]}" up -d
wait_for_stashd "${COMPOSE_FILES[@]}"
run_boundary "${COMPOSE_FILES[@]}"
docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null

echo '--- required-rule sensitivity: restore Docker clone filter ---'
weak_profile="$TMP/stashd-plugin-bwrap-no-clone-delta.json"
python3 - "$SECCOMP_PROFILE" "$weak_profile" <<'PY'
import json
import sys

source, target = sys.argv[1:]
profile = json.load(open(source, encoding='utf-8'))
for rule in profile['syscalls']:
    if rule.get('names') == ['clone'] and rule.get('excludes', {}).get('arches') == ['s390', 's390x']:
        rule['args'][0]['value'] = 2114060288
        break
else:
    raise SystemExit('candidate clone rule not found')
json.dump(profile, open(target, 'w', encoding='utf-8'), indent='\t')
PY
set +e
weak_output=$(docker run --rm --user "$PUID:$PGID" --security-opt "seccomp=$weak_profile" \
    --security-opt apparmor=stashd-plugin-bwrap --entrypoint sh "$IMAGE" -lc "exec $bwrap_args" 2>&1)
weak_status=$?
set -e
printf 'exit=%s\n%s\n' "$weak_status" "$weak_output"
[ "$weak_status" -ne 0 ] || { echo 'weakened clone rule unexpectedly allowed bwrap' >&2; exit 1; }

cat >"$TMP/weak-compose.yml" <<YAML
services:
  stashd:
    security_opt: !override
      - seccomp=$weak_profile
      - apparmor=stashd-plugin-bwrap
YAML
export STASHD_DATA_DIR="$TMP/weak-data" STASHD_MEDIA_DIR="$TMP/weak-media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
docker compose -f "$ROOT/docker-compose.yml" -f "$TMP/weak-compose.yml" up -d
set +e
wait_for_stashd -f "$ROOT/docker-compose.yml" -f "$TMP/weak-compose.yml"
weak_boundary_status=0
run_boundary -f "$ROOT/docker-compose.yml" -f "$TMP/weak-compose.yml" || weak_boundary_status=$?
set -e
docker compose -f "$ROOT/docker-compose.yml" -f "$TMP/weak-compose.yml" down -v --remove-orphans >/dev/null
[ "$weak_boundary_status" -ne 0 ] || { echo 'weakened clone rule unexpectedly allowed the real plugin boundary' >&2; exit 1; }

echo '--- restored candidate seccomp: real PluginRunner boundary ---'
export STASHD_DATA_DIR="$TMP/restored-data" STASHD_MEDIA_DIR="$TMP/restored-media"
mkdir -p "$STASHD_DATA_DIR" "$STASHD_MEDIA_DIR"
docker compose "${COMPOSE_FILES[@]}" up -d
wait_for_stashd "${COMPOSE_FILES[@]}"
run_boundary "${COMPOSE_FILES[@]}"

echo 'authoritative seccomp plugin sandbox proof passed'

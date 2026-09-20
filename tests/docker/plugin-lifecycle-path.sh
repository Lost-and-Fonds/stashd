#!/usr/bin/env bash
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
COMPOSE_PROJECT_NAME="stashd-plugin-lifecycle-${RANDOM}-${RANDOM}"
export COMPOSE_PROJECT_NAME
export STASHD_IMAGE="${STASHD_PLUGIN_LIFECYCLE_IMAGE:-stashd:golden}"
export STASHD_HOST_PORT="${STASHD_PLUGIN_LIFECYCLE_PORT:-18475}"
COMPOSE_FILES=(-f "$ROOT/docker-compose.yml")
if [ "${STASHD_GOLDEN_APPARMOR:-0}" = "1" ]; then
    COMPOSE_FILES+=(-f "$ROOT/docker-compose.apparmor.yml")
fi

TMP=$(mktemp -d)
REGISTRY_CONTAINER="${COMPOSE_PROJECT_NAME}-registry"
NETWORK="${COMPOSE_PROJECT_NAME}_default"
REGISTRY_CERT="$TMP/registry/cert.pem"

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        docker compose "${COMPOSE_FILES[@]}" logs stashd >&2 2>/dev/null || true
        docker logs "$REGISTRY_CONTAINER" >&2 2>/dev/null || true
    fi
    docker rm -f "$REGISTRY_CONTAINER" >/dev/null 2>&1 || true
    docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

mkdir -p "$TMP/registry" "$TMP/artifacts"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=fixture-registry' \
    -addext 'subjectAltName=DNS:fixture-registry' \
    -keyout "$TMP/registry/key.pem" -out "$REGISTRY_CERT" >/dev/null 2>&1

docker compose "${COMPOSE_FILES[@]}" up -d
stashd_container=$(docker compose "${COMPOSE_FILES[@]}" ps -q stashd)
for _ in $(seq 1 180); do
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$stashd_container" 2>/dev/null || true)
    [ "$health" = healthy ] && break
    sleep 2
done
[ "$health" = healthy ] || { echo 'plugin lifecycle: Stashd did not become healthy' >&2; exit 1; }

docker run -d --name "$REGISTRY_CONTAINER" --network "$NETWORK" --network-alias fixture-registry \
    -e REGISTRY_HTTP_ADDR=0.0.0.0:5000 \
    -e REGISTRY_HTTP_TLS_CERTIFICATE=/certs/cert.pem \
    -e REGISTRY_HTTP_TLS_KEY=/certs/key.pem \
    -v "$TMP/registry:/certs:ro" registry:2 >/dev/null

for _ in $(seq 1 60); do
    if docker run --rm --network "$NETWORK" -v "$REGISTRY_CERT:/certs/cert.pem:ro" --entrypoint sh "$STASHD_IMAGE" -ec \
        'curl --connect-timeout 1 --max-time 3 -fsS --cacert /certs/cert.pem https://fixture-registry:5000/v2/' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

docker compose "${COMPOSE_FILES[@]}" cp "$REGISTRY_CERT" stashd:/usr/local/share/ca-certificates/fixture-registry.crt
timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T stashd update-ca-certificates >/dev/null

docker run --rm --user 0 \
    -v "$ROOT:/source:ro" -v "$TMP/artifacts:/artifacts" \
    --entrypoint php "$STASHD_IMAGE" /source/tests/docker/plugin-lifecycle-fixtures.php
cat "$TMP/artifacts/identities.json"

docker run --rm --user 0 --network "$NETWORK" \
    -v "$TMP/artifacts:/artifacts:ro" -v "$REGISTRY_CERT:/usr/local/share/ca-certificates/fixture-registry.crt:ro" \
    --entrypoint sh "$STASHD_IMAGE" -ec '
        update-ca-certificates >/dev/null
        /usr/local/libexec/stashd/oras cp --from-oci-layout /artifacts/a/layout:stashd fixture-registry:5000/stashd/lifecycle-fixture:1.0.0
        /usr/local/libexec/stashd/oras cp --from-oci-layout /artifacts/conflict/layout:stashd fixture-registry:5000/stashd/lifecycle-fixture:conflict
        /usr/local/libexec/stashd/oras cp --from-oci-layout /artifacts/c/layout:stashd fixture-registry:5000/stashd/lifecycle-fixture:1.1.0
    '

DIGEST_A=$(jq -r '.a.digest' "$TMP/artifacts/identities.json")
DIGEST_B=$(jq -r '.conflict.digest' "$TMP/artifacts/identities.json")
DIGEST_C=$(jq -r '.c.digest' "$TMP/artifacts/identities.json")
[ "$DIGEST_A" != "$DIGEST_B" ] && [ "$DIGEST_A" != "$DIGEST_C" ] && [ "$DIGEST_B" != "$DIGEST_C" ]
REF_A="fixture-registry:5000/stashd/lifecycle-fixture@${DIGEST_A}"
REF_B="fixture-registry:5000/stashd/lifecycle-fixture@${DIGEST_B}"
REF_C="fixture-registry:5000/stashd/lifecycle-fixture@${DIGEST_C}"

console() { timeout 180s docker compose "${COMPOSE_FILES[@]}" exec -T stashd php tempest "$@"; }
runtime() {
    timeout 60s docker compose "${COMPOSE_FILES[@]}" exec -T -e STASHD_LIFECYCLE_EXPECTED="$1" \
        stashd php /tmp/plugin-lifecycle-run.php
}
list_plugins() { console stashd:plugin-list; }
package_count() {
    docker compose "${COMPOSE_FILES[@]}" exec -T stashd sh -c \
        "find /data/plugins/packages/lifecycle-fixture -mindepth 1 -maxdepth 1 -type d | wc -l" | tr -d '[:space:]'
}

docker compose "${COMPOSE_FILES[@]}" cp "$ROOT/tests/docker/plugin-lifecycle-run.php" stashd:/tmp/plugin-lifecycle-run.php

console stashd:plugin-install "$REF_A"
list_a=$(list_plugins); printf '%s\n' "$list_a"
printf '%s\n' "$list_a" | grep -F "lifecycle-fixture 1.0.0 php $DIGEST_A $REF_A" >/dev/null
runtime 'Lifecycle 1.0.0'
count_a=$(package_count)

console stashd:plugin-install "$REF_A"
list_reinstall=$(list_plugins); printf '%s\n' "$list_reinstall"
printf '%s\n' "$list_reinstall" | grep -F "lifecycle-fixture 1.0.0 php $DIGEST_A $REF_A" >/dev/null
[ "$(package_count)" = "$count_a" ]
runtime 'Lifecycle 1.0.0'

set +e
conflict_output=$(console stashd:plugin-install "$REF_B" 2>&1)
conflict_status=$?
set -e
printf '%s\n' "$conflict_output"
[ "$conflict_status" -ne 0 ]
printf '%s' "$conflict_output" | grep -F 'a different plugin artifact already uses this version' >/dev/null
list_conflict=$(list_plugins); printf '%s\n' "$list_conflict"
printf '%s\n' "$list_conflict" | grep -F "lifecycle-fixture 1.0.0 php $DIGEST_A $REF_A" >/dev/null
runtime 'Lifecycle 1.0.0'

console stashd:plugin-install "$REF_C"
list_c=$(list_plugins); printf '%s\n' "$list_c"
printf '%s\n' "$list_c" | grep -F "lifecycle-fixture 1.1.0 php $DIGEST_C $REF_C" >/dev/null
docker compose "${COMPOSE_FILES[@]}" exec -T stashd test -f /data/plugins/packages/lifecycle-fixture/1.0.0/install.json
runtime 'Lifecycle 1.1.0'

docker compose "${COMPOSE_FILES[@]}" up -d --force-recreate --no-deps stashd >/dev/null
stashd_container=$(docker compose "${COMPOSE_FILES[@]}" ps -q stashd)
for _ in $(seq 1 180); do
    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$stashd_container" 2>/dev/null || true)
    [ "$health" = healthy ] && break
    sleep 2
done
[ "$health" = healthy ] || { echo 'plugin lifecycle: recreated Stashd did not become healthy' >&2; exit 1; }
list_persisted=$(list_plugins); printf '%s\n' "$list_persisted"
printf '%s\n' "$list_persisted" | grep -F "lifecycle-fixture 1.1.0 php $DIGEST_C $REF_C" >/dev/null
runtime 'Lifecycle 1.1.0'
echo 'plugin lifecycle proof passed'

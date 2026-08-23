#!/bin/sh
set -eu

printf '%s\n' "${M1_VAULT_CANARY:?}" > /outer-vault/DO_NOT_READ
export STASHD_DATABASE_URL='postgres://secret-user:secret-password@db/stashd'
export STASHD_ENCRYPTION_KEY='do-not-cross-the-boundary'

exec /m1/assert.sh

#!/bin/sh
set -eu

CANARY=${M7_VAULT_CANARY:?missing vault canary}
printf '%s' "$CANARY" > /vault/DO_NOT_READ
export STASHD_SECRET='outer-secret-must-not-cross'

php -d zend.assertions=1 -d assert.exception=1 /m7/m7/test_m7.php

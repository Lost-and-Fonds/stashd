#!/bin/sh
set -eu

printf '%s\n' "${M5_VAULT_CANARY:?}" > /vault/DO_NOT_READ
export STASHD_SECRET='m5-secret-must-not-cross'
exec php -d zend.assertions=1 -d assert.exception=1 /m5/m5/test_m5.php

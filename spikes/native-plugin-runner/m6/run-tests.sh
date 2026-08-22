#!/bin/sh
set -eu

printf '%s\n' "${M6_VAULT_CANARY:?}" > /vault/DO_NOT_READ
export STASHD_SECRET='m6-secret-must-not-cross'
exec php -d zend.assertions=1 -d assert.exception=1 /m6/m6/test_m6.php

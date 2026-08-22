#!/bin/sh
set -eu

printf '%s' "${M7_VAULT_CANARY:?missing vault canary}" > /vault/DO_NOT_READ
export STASHD_SECRET='outer-secret-must-not-cross'
php -d zend.assertions=1 -d assert.exception=1 /src/native-plugin-runtime/tests/production_m7.php

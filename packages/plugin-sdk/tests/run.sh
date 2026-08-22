#!/bin/sh
set -eu
php -d zend.assertions=1 -d assert.exception=1 "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/unit.php"

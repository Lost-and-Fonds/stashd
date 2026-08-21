#!/bin/sh
set -euo pipefail

export ENVIRONMENT="${ENVIRONMENT:-testing}"
export DB_CONNECTION=pgsql
export DB_HOST="${DB_HOST:-lerd-postgres}"
export DB_PORT="${DB_PORT:-5432}"
export DB_DATABASE="${DB_DATABASE:-stashd_testing}"
export DB_USERNAME="${DB_USERNAME:-postgres}"
export DB_PASSWORD="${DB_PASSWORD:-lerd}"

exec vendor/bin/pest "$@"

#!/bin/sh
set -eu

# Read-only capture from a Docker-hosted Stashd deployment.  The output path is
# already ignored by core/.gitignore; never point it at a tracked directory.
remote_host=${REMOTE_HOST:-morrie}
db_container=${REMOTE_DB_CONTAINER:-stashd-postgres}
capture_date=$(date +%F)
output_dir=${1:-.stashd/upgrade-fixtures/private/live-$capture_date}

if [ -e "$output_dir" ]; then
  echo "Refusing to overwrite existing capture: $output_dir" >&2
  exit 1
fi

mkdir -p "$output_dir"
ssh -o BatchMode=yes "$remote_host" \
  "sudo -n docker exec $db_container pg_dump -U stashd -d stashd --format=custom --no-owner --no-privileges --exclude-table=public.users --exclude-table=public.api_tokens --exclude-table=public.login_attempts --exclude-table=public.secrets" \
  > "$output_dir/postgres.custom"

ssh -o BatchMode=yes "$remote_host" \
  "sudo -n docker inspect stashd --format '{{index .Config.Image}}|{{index .Image}}'" \
  > "$output_dir/image.txt"

printf '%s\n' \
  "capture_date=$capture_date" \
  "remote_host=$remote_host" \
  "database=postgresql" \
  "excluded_tables=users,api_tokens,login_attempts,secrets" \
  "dump=postgres.custom" \
  > "$output_dir/README.txt"

echo "Captured private database snapshot at $output_dir"

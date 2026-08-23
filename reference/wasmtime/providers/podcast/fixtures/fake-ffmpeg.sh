#!/bin/sh
set -eu

input=""
output=""
previous=""
for argument in "$@"; do
    if [ "$previous" = "-i" ]; then
        input="$argument"
    fi
    output="$argument"
    previous="$argument"
done

[ -n "$input" ]
[ -f "$input" ]
[ -n "$output" ]
printf 'fixture-derived-audio\nsource=%s\n' "$input" > "$output"

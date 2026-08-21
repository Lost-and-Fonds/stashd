#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
socket_dir="$(mktemp -d)"
stage_dir="$(mktemp -d)"
socket="$socket_dir/plugin.sock"

cleanup() {
    if [[ -n "${host_pid:-}" ]]; then
        kill "$host_pid" 2>/dev/null || true
        wait "$host_pid" 2>/dev/null || true
    fi
    rm -rf "$socket_dir" "$stage_dir"
}
trap cleanup EXIT

cd "$root"
cargo build -p stashd-plugin-host --release
cargo build -p stashd-podcast-plugin --target wasm32-wasip2 --release

target/release/stashd-plugin-host serve "$socket" >/tmp/stashd-podcast-plugin-host.log 2>&1 &
host_pid=$!
for _ in $(seq 1 50); do
    [[ -S "$socket" ]] && break
    sleep 0.1
done
[[ -S "$socket" ]] || { cat /tmp/stashd-podcast-plugin-host.log; exit 1; }

php_bin="${STASHD_PHP_BIN:-}"
if [[ -z "$php_bin" && -x /usr/bin/php ]]; then
    php_bin=/usr/bin/php
fi
php_bin="${php_bin:-php}"
"$php_bin" /dev/stdin "$socket" "$root/target/wasm32-wasip2/release/stashd_podcast_plugin.wasm" "$stage_dir" <<'PHP'
<?php

$socket = $argv[1] ?? '';
$component = $argv[2] ?? '';
$stage = $argv[3] ?? '';
$stream = stream_socket_client('unix://' . $socket, $errno, $error, 5);
if (! is_resource($stream)) {
    throw new RuntimeException($error ?: 'Could not connect to plugin host.');
}

$request = [
    'id' => 'podcast-spike',
    'op' => 'broadcast-publish',
    'component_path' => $component,
    'staging_dir' => $stage,
    'broadcast' => [
        'broadcast_id' => 'broadcast-fixture',
        'settings' => [
            ['key' => 'title', 'value' => ['kind' => 'text', 'value' => 'Fixture Podcast']],
        ],
        'public_base_url' => 'https://stashd.test',
        'broadcast_token' => 'broadcast-token',
        'episodes' => [[
            'id' => 'episode-fixture',
            'publication_token' => 'item-token',
            'title' => 'Fixture Episode',
            'description' => 'A deterministic fixture episode.',
            'published_at' => 'Wed, 01 Jan 2025 00:00:00 +0000',
            'duration_seconds' => 42,
            'media_reference' => 'episode.mp3',
            'media_url' => 'https://stashd.test/published/publication-fixture/access/fixture-credential',
            'media_type' => 'audio/mpeg',
            'media_size_bytes' => 123,
            'artwork_reference' => null,
            'transcript_reference' => null,
            'chapter_reference' => null,
        ]],
    ],
];

fwrite($stream, json_encode($request, JSON_THROW_ON_ERROR) . "\n");
$events = [];
while (($line = fgets($stream)) !== false) {
    $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
}
fclose($stream);

$publication = array_values(array_filter($events, static fn (array $event): bool => ($event['event'] ?? null) === 'broadcast_published'))[0]['publication'] ?? null;
$reference = $publication['artifact']['reference'] ?? null;
if (! is_string($reference) || ! is_file($stage . '/' . $reference)) {
    throw new RuntimeException('Podcast Component did not return a staged feed.');
}

$feed = file_get_contents($stage . '/' . $reference);
if (! is_string($feed) || ! str_contains($feed, '<enclosure ') || ! str_contains($feed, 'Fixture Episode') || ! str_contains($feed, '/published/publication-fixture/access/fixture-credential')) {
    throw new RuntimeException('Podcast Component feed output was incomplete.');
}

echo "Podcast Broadcast Component spike passed\n";
PHP

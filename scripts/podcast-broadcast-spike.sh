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
"$php_bin" /dev/stdin "$socket" "$root/target/wasm32-wasip2/release/stashd_podcast_plugin.wasm" "$stage_dir" "$root/tests/fixtures/podcast/fake-ffmpeg.sh" "$root/tests/fixtures/podcast" <<'PHP'
<?php

$socket = $argv[1] ?? '';
$component = $argv[2] ?? '';
$stage = $argv[3] ?? '';
$helper = $argv[4] ?? '';
$packageRoot = $argv[5] ?? '';
$stream = stream_socket_client('unix://' . $socket, $errno, $error, 5);
if (! is_resource($stream)) {
    throw new RuntimeException($error ?: 'Could not connect to plugin host.');
}

file_put_contents($stage . '/source-video.mp4', 'fixture-video');

$broadcast = [
    'reference' => 'broadcast-fixture',
    'settings' => [
        ['key' => 'title', 'value' => ['kind' => 'text', 'value' => 'Fixture Podcast']],
        ['key' => 'media_kind', 'value' => ['kind' => 'text', 'value' => 'audio']],
    ],
    'items' => [[
        'id' => 'episode-fixture',
        'title' => 'Fixture Episode',
        'description' => 'A deterministic fixture episode.',
        'published_at' => 'Wed, 01 Jan 2025 00:00:00 +0000',
        'duration_seconds' => 42,
        'resources' => [[
            'reference' => 'source-video.mp4',
            'kind' => 'video',
            'url' => 'https://stashd.test/published/source/access/video',
            'media_type' => 'video/mp4',
            'size_bytes' => 123,
        ]],
    ]],
];

$prepare = [
    'id' => 'podcast-prepare-spike',
    'op' => 'broadcast-prepare',
    'component_path' => $component,
    'staging_dir' => $stage,
    'helper_name' => 'ffmpeg',
    'helper_executable' => $helper,
    'helper_package_root' => $packageRoot,
    'broadcast' => $broadcast,
];

fwrite($stream, json_encode($prepare, JSON_THROW_ON_ERROR) . "\n");
$events = [];
while (($line = fgets($stream)) !== false) {
    $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
}
fclose($stream);

$preparation = array_values(array_filter($events, static fn (array $event): bool => ($event['event'] ?? null) === 'broadcast_prepared'))[0]['preparation'] ?? null;
$derived = $preparation['artifacts'][0]['reference'] ?? null;
if (! is_string($derived) || ! is_file($stage . '/' . $derived)) {
    throw new RuntimeException('Podcast Component did not return a staged audio artifact.');
}

$publishStream = stream_socket_client('unix://' . $socket, $errno, $error, 5);
if (! is_resource($publishStream)) {
    throw new RuntimeException($error ?: 'Could not reconnect to plugin host.');
}
$publish = [
    'id' => 'podcast-publish-spike',
    'op' => 'broadcast-publish',
    'component_path' => $component,
    'staging_dir' => $stage,
    'broadcast' => $broadcast,
];
$publish['broadcast']['items'][0]['resources'] = [[
    'reference' => $derived,
    'kind' => 'audio',
    'url' => 'https://stashd.test/published/publication-fixture/access/fixture-credential',
    'media_type' => 'audio/mpeg',
    'size_bytes' => filesize($stage . '/' . $derived),
]];
fwrite($publishStream, json_encode($publish, JSON_THROW_ON_ERROR) . "\n");
$publishEvents = [];
while (($line = fgets($publishStream)) !== false) {
    $publishEvents[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
}
fclose($publishStream);

$publication = array_values(array_filter($publishEvents, static fn (array $event): bool => ($event['event'] ?? null) === 'broadcast_published'))[0]['publication'] ?? null;
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

<?php

declare(strict_types=1);

require __DIR__ . '/FrameCodec.php';

$mode = $argv[1] ?? 'normal';
if ($mode === 'malformed') {
    fwrite(STDOUT, pack('N', 5) . 'oops!');
    exit(0);
}
if ($mode === 'crash') {
    exit(17);
}

$hello = FrameCodec::read(STDIN, 2.0);
if (! is_array($hello) || ($hello['method'] ?? null) !== 'hello') {
    exit(18);
}
if (($hello['params']['max'] ?? 0) < 1 || ($hello['params']['min'] ?? 2) > 1) {
    FrameCodec::write(STDOUT, [
        'protocol' => 1,
        'id' => $hello['id'] ?? null,
        'kind' => 'response',
        'error' => ['code' => 'protocol-mismatch', 'message' => 'fixture supports protocol 1'],
    ]);
    exit(0);
}
FrameCodec::write(STDOUT, [
    'protocol' => 1,
    'id' => $hello['id'] ?? null,
    'kind' => 'response',
    'result' => ['protocol' => 1, 'min' => 1, 'max' => 1],
]);
fwrite(STDERR, "fixture stderr log\n");

if ($mode === 'hang') {
    sleep(5);
    exit(0);
}

$invoke = FrameCodec::read(STDIN, 2.0);
if (! is_array($invoke) || ($invoke['method'] ?? null) !== 'invoke') {
    exit(19);
}

if ($mode === 'cancel') {
    FrameCodec::write(STDOUT, [
        'protocol' => 1,
        'kind' => 'notification',
        'method' => 'progress',
        'params' => ['stage' => 'waiting'],
    ]);
    $cancel = FrameCodec::read(STDIN, 2.0);
    if (($cancel['method'] ?? null) !== 'cancel') {
        exit(20);
    }
    FrameCodec::write(STDOUT, [
        'protocol' => 1,
        'id' => $invoke['id'] ?? null,
        'kind' => 'response',
        'error' => ['code' => 'cancelled', 'message' => 'fixture cancelled'],
    ]);
    exit(0);
}

FrameCodec::write(STDOUT, [
    'protocol' => 1,
    'id' => 'host-1',
    'kind' => 'request',
    'method' => 'host.echo',
    'params' => ['value' => 'fixture'],
]);
$hostResponse = FrameCodec::read(STDIN, 2.0);
if (($hostResponse['id'] ?? null) !== 'host-1') {
    exit(21);
}
FrameCodec::write(STDOUT, [
    'protocol' => 1,
    'kind' => 'notification',
    'method' => 'log',
    'params' => ['message' => 'fixture log'],
]);
FrameCodec::write(STDOUT, [
    'protocol' => 1,
    'kind' => 'notification',
    'method' => 'progress',
    'params' => ['stage' => 'complete'],
]);
FrameCodec::write(STDOUT, [
    'protocol' => 1,
    'id' => $invoke['id'] ?? null,
    'kind' => 'response',
    'result' => ['echo' => $hostResponse['result']['value'] ?? null],
]);

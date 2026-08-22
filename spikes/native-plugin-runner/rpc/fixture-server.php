<?php

declare(strict_types=1);

require __DIR__ . '/FrameCodec.php';

while (($message = FrameCodec::read(STDIN)) !== null) {
    if (($message['kind'] ?? null) === 'notification') {
        continue;
    }
    if (($message['kind'] ?? null) !== 'request' || ! is_string($message['id'] ?? null)) {
        throw new FrameProtocolError('fixture server requires identified requests');
    }
    $method = $message['method'] ?? null;
    if ($method === 'hello') {
        FrameCodec::write(STDOUT, [
            'protocol' => 1,
            'id' => $message['id'],
            'kind' => 'response',
            'result' => ['protocol' => 1],
        ]);
    } elseif ($method === 'ping') {
        FrameCodec::write(STDOUT, [
            'protocol' => 1,
            'id' => $message['id'],
            'kind' => 'response',
            'result' => ['pong' => $message['params']['value'] ?? null],
        ]);
    }
}

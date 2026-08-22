<?php

declare(strict_types=1);

require __DIR__.'/FrameCodec.php';

$pipes = [];
$process = proc_open(['php', __DIR__.'/fixture-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (! is_resource($process)) {
    throw new RuntimeException('could not start PHP fixture server');
}

FrameCodec::write($pipes[0], ['protocol' => 1, 'id' => 'php-hello', 'kind' => 'request', 'method' => 'hello', 'params' => ['min' => 1, 'max' => 1]]);
$hello = FrameCodec::read($pipes[1], 1.0);
FrameCodec::write($pipes[0], ['protocol' => 1, 'kind' => 'notification', 'method' => 'log', 'params' => ['message' => 'ignored notification']]);
FrameCodec::write($pipes[0], ['protocol' => 1, 'id' => 'php-ping', 'kind' => 'request', 'method' => 'ping', 'params' => ['value' => 'php']]);
$pong = FrameCodec::read($pipes[1], 1.0);
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
if (($hello['id'] ?? null) !== 'php-hello' || ($pong['result']['pong'] ?? null) !== 'php' || $exitCode !== 0) {
    throw new RuntimeException('PHP fixture client protocol check failed');
}
echo "M2 PHP fixture client: PASS\n";

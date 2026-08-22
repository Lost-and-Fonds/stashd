<?php

declare(strict_types=1);

require __DIR__ . '/FrameCodec.php';

$mode = $argv[1] ?? 'normal';
$packageRoot = realpath(__DIR__);
if ($packageRoot === false) {
    fwrite(STDERR, "RPC fixture package is unavailable\n");
    exit(2);
}

$command = [
    'bwrap',
    '--die-with-parent',
    '--new-session',
    '--unshare-user',
    '--unshare-pid',
    '--unshare-ipc',
    '--unshare-uts',
    '--unshare-net',
    '--clearenv',
    '--ro-bind', $packageRoot, '/plugin',
    '--tmpfs', '/tmp',
    '--dev', '/dev',
    '--ro-bind', '/usr', '/usr',
    '--ro-bind', '/bin', '/bin',
    '--ro-bind', '/lib', '/lib',
    '--ro-bind', '/lib64', '/lib64',
    '--ro-bind', '/sbin', '/sbin',
    '--dir', '/etc',
    '--dir', '/home',
    '--dir', '/root',
    '--dir', '/run',
    '--chdir', '/plugin',
    '--setenv', 'HOME', '/tmp',
    '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin',
    '--',
    'php', '/plugin/native-plugin.php', $mode,
];

$pipes = [];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (! is_resource($process)) {
    fwrite(STDERR, "could not start RPC fixture sandbox\n");
    exit(2);
}
stream_set_blocking($pipes[1], true);
stream_set_blocking($pipes[2], false);

$stderr = '';
$summary = ['mode' => $mode, 'notifications' => [], 'stderr' => ''];

try {
    if ($mode === 'malformed' || $mode === 'crash') {
        try {
            FrameCodec::read($pipes[1], 0.5);
            throw new RuntimeException('fixture failure was not detected');
        } catch (FrameTimeout|FrameProtocolError $exception) {
            $summary['error'] = $exception::class;
        }
        finish($process, $pipes, $stderr, $summary);
    }

    $helloRange = $mode === 'mismatch' ? ['min' => 2, 'max' => 2] : ['min' => 1, 'max' => 1];
    FrameCodec::write($pipes[0], request('hello-1', 'hello', $helloRange));
    $hello = FrameCodec::read($pipes[1], 2.0);
    assertResponse($hello, 'hello-1');
    if ($mode === 'mismatch') {
        if (($hello['error']['code'] ?? null) !== 'protocol-mismatch') {
            throw new RuntimeException('incompatible protocol was accepted');
        }
        $summary['response'] = $hello;
        $summary['negotiation'] = 'rejected';
        finish($process, $pipes, $stderr, $summary);
    }
    if (($hello['result']['protocol'] ?? null) !== 1) {
        throw new RuntimeException('protocol negotiation failed');
    }

    FrameCodec::write($pipes[0], request('invoke-1', 'invoke', []));
    $cancelSent = false;
    while (true) {
        $message = FrameCodec::read($pipes[1], $mode === 'hang' ? 0.25 : 2.0);
        if ($message === null) {
            throw new RuntimeException('fixture closed before response');
        }
        if (($message['kind'] ?? null) === 'request') {
            if (($message['method'] ?? null) !== 'host.echo') {
                throw new RuntimeException('unexpected host request');
            }
            FrameCodec::write($pipes[0], response($message['id'] ?? null, ['value' => 'host-response']));
            continue;
        }
        if (($message['kind'] ?? null) === 'notification') {
            $summary['notifications'][] = $message['method'] ?? null;
            if ($mode === 'cancel' && ! $cancelSent && ($message['method'] ?? null) === 'progress') {
                FrameCodec::write($pipes[0], [
                    'protocol' => 1,
                    'kind' => 'notification',
                    'method' => 'cancel',
                    'params' => ['request_id' => 'invoke-1'],
                ]);
                $cancelSent = true;
            }
            continue;
        }
        if (($message['id'] ?? null) !== 'invoke-1') {
            throw new RuntimeException('response ID mismatch');
        }
        $summary['response'] = $message;
        break;
    }
    finish($process, $pipes, $stderr, $summary);
} catch (FrameTimeout $exception) {
    $summary['timed_out'] = true;
    if (is_resource($process)) {
        proc_terminate($process, 15);
    }
    usleep(100_000);
    if (is_resource($process) && proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    finish($process, $pipes, $stderr, $summary);
} catch (Throwable $exception) {
    $summary['error'] = $exception->getMessage();
    if (is_resource($process) && proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    finish($process, $pipes, $stderr, $summary);
}

function request(string $id, string $method, array $params): array
{
    return ['protocol' => 1, 'id' => $id, 'kind' => 'request', 'method' => $method, 'params' => $params];
}

function response(mixed $id, array $result): array
{
    return ['protocol' => 1, 'id' => $id, 'kind' => 'response', 'result' => $result];
}

function assertResponse(?array $message, string $id): void
{
    if (($message['kind'] ?? null) !== 'response' || ($message['id'] ?? null) !== $id) {
        throw new RuntimeException('invalid response or request ID');
    }
}

function finish($process, array $pipes, string &$stderr, array $summary): never
{
    if (is_resource($pipes[2])) {
        $stderr .= stream_get_contents($pipes[2]);
    }
    $summary['stderr'] = $stderr;
    $summary['exit_code'] = proc_close($process);
    if (is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
    if (is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR) . "\n");
    exit(($summary['timed_out'] ?? false) ? 124 : 0);
}

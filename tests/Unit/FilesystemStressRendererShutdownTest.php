<?php

declare(strict_types=1);

use App\Development\FilesystemStressTask;

test('an unresponsive renderer is killed and reaped within the shutdown deadline', function (int $signal): void {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $pid = pcntl_fork();
    if ($pid === 0) {
        pcntl_signal(SIGHUP, SIG_IGN);
        fwrite($sockets[1], 'ready');
        posix_kill(posix_getpid(), $signal);
        while (true) {
            usleep(10_000);
        }
    }

    expect($pid)->toBeGreaterThan(0);
    fclose($sockets[1]);
    expect(fread($sockets[0], 5))->toBe('ready');
    fclose($sockets[0]);
    $task = new FilesystemStressTask();
    new ReflectionProperty($task, 'pid')->setValue($task, $pid);
    $started = hrtime(true);
    try {
        expect(new ReflectionMethod($task, 'stopRenderer')->invoke($task))->toBeNull();
        expect((hrtime(true) - $started) / 1e9)->toBeLessThan(2.5);
        expect(is_dir('/proc/' . $pid))->toBeFalse();
        expect(new ReflectionProperty($task, 'pid')->getValue($task))->toBe(0);
    } finally {
        if (is_dir('/proc/' . $pid)) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
})->with(['ignored hangup' => [SIGCONT], 'stopped renderer' => [SIGSTOP]]);

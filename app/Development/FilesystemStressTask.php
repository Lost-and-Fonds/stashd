<?php

declare(strict_types=1);

namespace App\Development;

use Closure;
use Laravel\Prompts\Support\Logger;
use Laravel\Prompts\Task;
use Laravel\Prompts\Themes\Default\TaskRenderer;
use RuntimeException;
use Throwable;

/**
 * Keep Laravel's task renderer while preserving the experiment's cancellation handlers.
 * Own only the renderer lifecycle; the experiment owns cancellation and worker processes.
 */
final class FilesystemStressTask extends Task
{
    /**
     * Reuse the prototype's unchanged library renderer for this lifecycle-only Task subclass.
     */
    protected function getRenderer(): callable
    {
        return new TaskRenderer($this);
    }

    /**
     * Run the library's rendering and IPC without its immediate-exit SIGINT handler.
     * Task::run has no signal/fork lifecycle hook, and pcntl_signal unblocks a masked signal.
     * The callback stays in the owner; renderer children must never run copied resource destructors.
     *
     * @template T
     * @param Closure(Logger): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $this->limit = min($this->limit, $this->terminal()->lines() - 10);
        $this->recalculateMaxStableMessages();
        $this->capturePreviousNewLines();
        if (! static::output()->isDecorated()) {
            return $this->renderStatically($callback);
        }

        $asynchronous = pcntl_async_signals(true);
        /** @var callable|int $interrupt */
        $interrupt = pcntl_signal_get_handler(SIGINT);
        $mask = null;
        $failure = null;
        $rendererFailure = null;
        $result = null;
        try {
            $this->hideCursor();
            $this->render();
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($sockets === false) {
                return $this->renderStatically($callback);
            }

            pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $mask);
            $this->pid = pcntl_fork();
            if ($this->pid === -1) {
                fclose($sockets[0]);
                fclose($sockets[1]);
                throw new RuntimeException('Could not start the Prompts renderer.');
            }

            if ($this->pid === 0) {
                pcntl_signal(SIGHUP, SIG_DFL);
                fclose($sockets[1]);
                stream_set_blocking($sockets[0], false);
                try {
                    while (true) {
                        $this->receiveMessages($sockets[0]);
                        if (! $this->finished) {
                            $this->render();
                            $this->count++;
                        }

                        usleep(max(1, $this->interval * 1000));
                    }
                } finally {
                    // A renderer exception must not close inherited PDO or worker handles.
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }

            fclose($sockets[0]);
            $this->socket = $sockets[1];
            pcntl_sigprocmask(SIG_SETMASK, $mask);
            $result = $callback(new FilesystemStressTaskLogger($this->identifier, $this->socket));
            $reset = $this->identifier . '_reset:' . ($asynchronous ? 1 : 0) . PHP_EOL;
            if (@fwrite($this->socket, $reset) !== strlen($reset)) {
                throw new RuntimeException('Prompts renderer became unavailable.');
            }

            usleep(max(1, $this->interval * 2000));
        } catch (Throwable $error) {
            $failure = $error;
            $this->resetTerminal($asynchronous, success: false, originalSignalHandler: $interrupt);
        } finally {
            $rendererFailure = $this->stopRenderer();
            pcntl_async_signals($asynchronous);
            if ($mask !== null) {
                pcntl_sigprocmask(SIG_SETMASK, $mask);
            }
        }

        if ($rendererFailure !== null) {
            throw new RuntimeException($rendererFailure->getMessage(), previous: $failure ?? $rendererFailure);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /**
     * Terminate and reap only this task's renderer, reporting failure without hiding callback errors.
     */
    private function stopRenderer(): ?RuntimeException
    {
        if (! isset($this->pid) || $this->pid <= 0) {
            return null;
        }

        $pid = $this->pid;
        $this->pid = 0;
        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }

        posix_kill($pid, SIGHUP);
        $status = 0;
        do {
            $waited = pcntl_waitpid($pid, $status);
        } while ($waited === -1 && pcntl_get_last_error() === PCNTL_EINTR);

        if ($waited !== $pid) {
            return new RuntimeException('Prompts renderer cleanup could not be confirmed.');
        }

        return pcntl_wifsignaled($status) && in_array(pcntl_wtermsig($status), [SIGHUP, SIGINT, SIGTERM], true)
            ? null
            : new RuntimeException('Prompts renderer exited unexpectedly.');
    }
}

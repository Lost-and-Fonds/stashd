<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Identity and liveness of worker tick processes, used by stale-job recovery
 * to decide whether a Processing job's owner is actually gone. A token is
 * "{pid}:{starttime}" where starttime is the process start time in clock
 * ticks from /proc/{pid}/stat — the starttime guards against PID reuse, so a
 * recycled PID can never make a dead owner look alive. On non-Linux dev
 * machines (no /proc) the token is just "{pid}" and liveness falls back to
 * posix_kill(pid, 0) without the reuse guard.
 *
 * All worker lanes run inside the same container (one supervisord), so every
 * lane sees every other lane's PIDs.
 */
final readonly class WorkerProcessProbe
{
    public function currentToken(): string
    {
        return $this->tokenForPid((int) getmypid());
    }

    public function tokenForPid(int $pid): string
    {
        $stat = $this->procStat($pid);

        return $stat === null ? (string) $pid : "{$pid}:{$stat['startTime']}";
    }

    public function isAlive(string $token): bool
    {
        [$pid, $startTime] = $this->parse($token);

        if ($pid <= 0) {
            return false;
        }

        $stat = $this->procStat($pid);

        if ($stat !== null) {
            // A zombie still has a /proc entry but isn't doing work anymore
            // (e.g. just SIGKILLed, parent hasn't reaped it yet).
            if ($stat['state'] === 'Z') {
                return false;
            }

            return $startTime === null || $stat['startTime'] === $startTime;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return false;
    }

    /**
     * SIGKILL, not SIGTERM: this is only called on an owner that has gone
     * silent past the hard stall cap (e.g. blocked on a dead network mount),
     * where a catchable signal may never be processed. A plugin helper child
     * of the killed tick is orphaned rather than killed; it exits on its own
     * within its helper timeout and only writes to staging temp.
     */
    public function kill(string $token): void
    {
        if (! $this->isAlive($token)) {
            return;
        }

        [$pid] = $this->parse($token);

        if (function_exists('posix_kill')) {
            posix_kill($pid, 9);

            return;
        }

        exec('kill -9 ' . $pid . ' 2>/dev/null');
    }

    /** @return array{int, int|null} */
    private function parse(string $token): array
    {
        $parts = explode(':', $token, 2);

        return [(int) $parts[0], isset($parts[1]) ? (int) $parts[1] : null];
    }

    /** @return array{state: string, startTime: int}|null */
    private function procStat(int $pid): ?array
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        if ($stat === false) {
            return null;
        }

        // Field 2 (comm) may contain spaces/parens; everything after the last
        // ')' is well-formed space-separated fields, starting at field 3
        // (state). starttime is field 22 overall = index 19 after comm.
        $afterComm = substr($stat, (int) strrpos($stat, ')') + 2);
        $fields = explode(' ', $afterComm);

        if (! isset($fields[19])) {
            return null;
        }

        return ['state' => $fields[0], 'startTime' => (int) $fields[19]];
    }
}

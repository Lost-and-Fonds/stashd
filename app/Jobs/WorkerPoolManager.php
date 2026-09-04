<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Config\StashdConfig;

/**
 * Chooses a conservative consumer count; process supervision owns the
 * consumers themselves. It never claims, retries, or inspects jobs.
 */
final readonly class WorkerPoolManager
{
    public function __construct(private StashdConfig $config) {}

    public function desiredWorkers(string $workload, int $queueDepth, int $currentWorkers, ?float $load = null, ?int $availableMemoryMb = null): int
    {
        $limits = $this->config->workers[$workload] ?? ['min_workers' => 1, 'max_workers' => 1];
        $min = max(1, $limits['min_workers']);
        $max = max($min, $limits['max_workers']);

        if ($load !== null && $load >= 0.85 || $availableMemoryMb !== null && $availableMemoryMb < 512) {
            return $min;
        }

        return $queueDepth <= $currentWorkers
            ? max($min, min($currentWorkers, $queueDepth))
            : min($max, max($min, $queueDepth));
    }

    public function run(string $workload, MessengerWorkerRunner $runner, MessengerTransportRegistry $transports): void
    {
        if (! function_exists('pcntl_fork')) {
            $runner->run($workload);

            return;
        }

        /** @var array<int, int> $children */
        $children = [];
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$children): void {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
            }
            exit(0);
        });
        pcntl_signal(SIGINT, static function () use (&$children): void {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
            }
            exit(0);
        });

        while (true) {
            foreach ($children as $key => $pid) {
                $status = 0;

                if (pcntl_waitpid($pid, $status, WNOHANG) > 0) {
                    unset($children[$key]);
                }
            }

            $depth = $transports->get($workload)->getMessageCount();
            $load = function_exists('sys_getloadavg') && is_array($loads = sys_getloadavg()) ? ($loads[0] / $this->cpuCount()) : null;
            $desired = $this->desiredWorkers($workload, $depth, count($children), $load, $this->availableMemoryMb());

            while (count($children) < $desired) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    break;
                }

                if ($pid === 0) {
                    $runner->run($workload);
                    exit(0);
                }
                $children[] = $pid;
            }

            while (count($children) > $desired) {
                $pid = array_pop($children);

                if (is_int($pid)) {
                    posix_kill($pid, SIGTERM);
                }
            }

            sleep(10);
        }
    }

    private function availableMemoryMb(): ?int
    {
        $memory = @file_get_contents('/proc/meminfo');

        if (! is_string($memory) || ! preg_match('/^MemAvailable:\s+(\d+) kB$/m', $memory, $match)) {
            return null;
        }

        return (int) $match[1] / 1024;
    }

    private function cpuCount(): int
    {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');

        return is_string($cpuInfo) ? max(1, substr_count($cpuInfo, "\nprocessor\t:")) : 1;
    }
}

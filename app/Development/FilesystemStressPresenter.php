<?php

declare(strict_types=1);

namespace App\Development;

use App\Development\DTO\FilesystemStressProgress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\StreamOutput;
use Tempest\Console\Console;

/**
 * Render a single sampled progress display, or restrained phase messages on stderr.
 * Machine-readable evidence belongs to stdout and is never decorated here.
 */
final class FilesystemStressPresenter
{
    private ?ProgressBar $bar = null;
    private ?string $phase = null;

    /**
     * Use Symfony's existing manually updated bar; Tempest's iterable bar cannot accept ticks.
     */
    public function __construct(
        private Console $console,
        bool $interactive,
    ) {
        if ($interactive) {
            $this->bar = new ProgressBar(new StreamOutput(STDERR, decorated: true), 100);
        }
    }

    /**
     * Show persisted counts and evidence-backed verification, without printing one line per Item.
     */
    public function update(FilesystemStressProgress $progress): void
    {
        $value = static fn (?int $count): string => $count === null ? '?' : (string) $count;
        $message = sprintf(
            '%s | %.1fs | discovered %s admitted %s settled %s preserved %d verified %d | Jobs outstanding %s failed %s | workers %d | %s settled/total-s | peak RSS %s MiB',
            $progress->phase,
            $progress->elapsedSeconds,
            $value($progress->discovered),
            $value($progress->admitted),
            $value($progress->settled),
            $progress->preserved,
            $progress->verified,
            $value($progress->outstanding),
            $value($progress->failed),
            $progress->activeWorkers,
            $progress->settled === null ? '?' : sprintf('%.2f', $progress->settled / max(0.001, $progress->elapsedSeconds)),
            $progress->peakRssKib === null ? '?' : sprintf('%.1f', $progress->peakRssKib / 1024),
        );
        $phaseProgress = $value($progress->phaseCompleted) . '/' . $progress->phaseTotal . ' ' . $progress->phaseUnit;
        if ($this->bar !== null) {
            $this->bar->setMessage($message);
            $this->bar->setMessage($phaseProgress, 'phase_progress');
            $this->bar->setFormat("%message%\n%bar% %phase_progress%");
            if ($this->phase !== $progress->phase) {
                $this->bar->clear();
                $this->bar->start($progress->phaseTotal);
            }

            $this->bar->setProgress($progress->phaseCompleted ?? 0);
            $this->bar->display();
        } elseif ($this->phase !== $progress->phase) {
            fwrite(STDERR, $message . ' | phase ' . $phaseProgress . "\n");
        }

        $this->phase = $progress->phase;
    }

    /**
     * End the display without finishing a failed bar or inventing verification success.
     *
     * @param array{status: string, failure_type?: ?string, ...} $report
     */
    public function finish(array $report, bool $json): void
    {
        if ($this->bar !== null) {
            $this->bar->clear();
        }

        if (is_string($report['report_file'] ?? null)) {
            fwrite(STDERR, 'Report: ' . $report['report_file'] . "\n");
        }

        if ($json) {
            $this->console->writeRaw(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");

            return;
        }

        $this->console->writeRaw(
            $report['status'] === 'passed'
                ? "PASS: 100 Items preserved; both publications independently verified; stable inodes; corruption rejected; fixture cleaned.\n"
                : 'FAIL: ' . ($report['failure_type'] ?? 'unknown') . '; no successful acceptance claim. See the retained report and checkpoints.' . "\n",
        );
    }
}

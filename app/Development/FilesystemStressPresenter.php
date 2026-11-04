<?php

declare(strict_types=1);

namespace App\Development;

use App\Development\DTO\FilesystemStressProgress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;
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
            $width = max(10, new Terminal()->getWidth() - 1);
            $counter = $value($progress->phaseCompleted) . '/' . $progress->phaseTotal;
            $rows = [
                sprintf('%s | %.1fs', $progress->phase, $progress->elapsedSeconds),
                sprintf('Items: discovered %s | admitted %s | settled %s', $value($progress->discovered), $value($progress->admitted), $value($progress->settled)),
                sprintf('Checked: preserved %d | output %d', $progress->preserved, $progress->verified),
                sprintf('Jobs: outstanding %s | failed %s | workers %d', $value($progress->outstanding), $value($progress->failed), $progress->activeWorkers),
                sprintf(
                    'Peak RSS: %s MiB | %s settled/total-s',
                    $progress->peakRssKib === null ? '?' : sprintf('%.1f', $progress->peakRssKib / 1024),
                    $progress->settled === null ? '?' : sprintf('%.2f', $progress->settled / max(0.001, $progress->elapsedSeconds)),
                ),
            ];
            $this->bar->setMessage(wordwrap(implode("\n", $rows), $width, "\n", true));
            $this->bar->setMessage($counter, 'phase_progress');
            $this->bar->setMessage(wordwrap($progress->phaseUnit, $width, "\n", true), 'phase_unit');
            $this->bar->setBarWidth(max(1, min(28, $width - strlen($counter) - 1)));
            $this->bar->setFormat("%message%\n%bar% %phase_progress%\n%phase_unit%");
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
     * Keep verifier and failure messages above the live display instead of corrupting its cursor position.
     * Messages remain on stderr so JSON stdout stays machine-readable.
     */
    public function diagnostic(string $message): void
    {
        $this->bar?->clear();
        fwrite(STDERR, $message);
        $this->bar?->display();
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

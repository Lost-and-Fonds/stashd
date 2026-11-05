<?php

declare(strict_types=1);

namespace App\Development;

use App\Development\DTO\FilesystemStressProgress;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;
use Tempest\Console\Console;

/**
 * Render a live stage checklist and two progress bars, or restrained phase messages on stderr.
 * Machine-readable evidence belongs to stdout and is never decorated here.
 */
final class FilesystemStressPresenter
{
    private const array STAGES = [
        'Prepare source files',
        'Discover files',
        'Acquire + verify preservation',
        'Rescan + verify no new versions',
        'Remove original source',
        'Publish + verify output',
        'Republish + verify stable inodes',
        'Reject deliberate corruption',
        'Clean up owned fixture',
    ];

    private const array PHASES = [
        'generate_source' => [0, 'Generate source files'],
        'verify_source' => [0, 'Check source bytes'],
        'discovery' => [1, 'Discover distinct files'],
        'first_acquisition' => [2, 'Drain acquisition Jobs'],
        'verify_preservation' => [2, 'Check canonical Items and Assets'],
        'unchanged_rescan' => [3, 'Rediscover unchanged files'],
        'unchanged_acquisition' => [3, 'Drain repeat acquisition Jobs'],
        'verify_unchanged_preservation' => [3, 'Check unchanged archival versions'],
        'remove_source' => [4, 'Remove source before publication'],
        'first_broadcast' => [5, 'Publish from the Vault'],
        'verify_first_publication' => [5, 'Independently check output bytes'],
        'repeat_broadcast' => [6, 'Repeat publication'],
        'verify_repeat_publication' => [6, 'Check output bytes and inodes'],
        'corruption_negative_control' => [7, 'Prove corrupt output is rejected'],
        'cleanup' => [8, 'Remove disposable fixture'],
    ];

    private ?ProgressBar $bar = null;
    private ?string $phase = null;
    private int $stage = 0;
    /**
     * Stages become done only when execution moves past them, not when a Job counter fills.
     *
     * @var array<int, true>
     */
    private array $completedStages = [];
    private ?FilesystemStressProgress $lastProgress = null;

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
            // Report pages belong to whichever publication is currently running.
            $stage = self::PHASES[$progress->phase][0] ?? $this->stage;
            if ($this->lastProgress !== null && $stage !== $this->stage) {
                $this->completedStages[$this->stage] = true;
            }

            $this->stage = $stage;
            $this->lastProgress = $progress;
            $this->render($progress);
        } elseif ($this->phase !== $progress->phase) {
            fwrite(STDERR, $message . ' | phase ' . $phaseProgress . "\n");
        }

        $this->phase = $progress->phase;
    }

    /**
     * Draw both real progress bars and the stage checklist as one Symfony-managed display.
     * Short terminals show a window around the active stage rather than overflowing the screen.
     */
    private function render(FilesystemStressProgress $progress, string $status = 'RUNNING'): void
    {
        $bar = $this->bar;
        assert($bar !== null, 'The dashboard is only rendered for an interactive progress bar.');
        $terminal = new Terminal();
        $width = max(20, $terminal->getWidth() - 1);
        $formatter = new OutputFormatter(true);
        $wrap = static fn (string $text): string => $formatter->formatAndWrap($text, $width);
        $value = static fn (?int $count): string => $count === null ? '?' : (string) $count;
        $passed = $status === 'PASSED';
        $indeterminate = ! $passed && $progress->phaseCompleted === null;
        $color = match ($status) {
            'PASSED' => 'green',
            'FAILED', 'CANCELLED' => 'red',
            default => 'cyan',
        };
        $counter = $indeterminate
            ? ($status === 'RUNNING' ? 'WORKING (no count yet)' : 'STOPPED')
            : sprintf(
                '%s/%d %s%%',
                $passed ? $progress->phaseTotal : $value($progress->phaseCompleted),
                $progress->phaseTotal,
                $passed ? '100' : ($progress->phaseCompleted === null ? '?' : (string) (int) ((100 * $progress->phaseCompleted) / max(1, $progress->phaseTotal))),
            );
        $header = $wrap(sprintf(
            '<fg=cyan;options=bold>FILESYSTEM STRESS</> | 100 Items | serial'
            . "\n"
            . '<fg=%s;options=bold>%s</> | %d/9 stages done | %.1fs'
            . "\n%s\n"
            . '<fg=gray>STAGES: [x] done  [>] active  [ ] todo</>'
            . "\n",
            $color,
            $status,
            count($this->completedStages),
            $progress->elapsedSeconds,
            $this->overallBar($width),
        ));
        $label = self::PHASES[$progress->phase][1] ?? 'Check publication reports';
        $current = $wrap('<options=bold>Now: ' . $label . '</>');
        $unit = $wrap($progress->phaseUnit);
        $metrics = $wrap(sprintf(
            'Items: discovered %s | admitted %s | settled %s'
            . "\n"
            . 'Checked: preserved %d | output %d'
            . "\n"
            . 'Jobs: outstanding %s | failed %s | workers %d'
            . "\n"
            . 'Peak RSS: %s MiB | %s settled/total-s',
            $value($progress->discovered),
            $value($progress->admitted),
            $value($progress->settled),
            $progress->preserved,
            $progress->verified,
            $value($progress->outstanding),
            $value($progress->failed),
            $progress->activeWorkers,
            $progress->peakRssKib === null ? '?' : sprintf('%.1f', $progress->peakRssKib / 1024),
            $progress->settled === null ? '?' : sprintf('%.2f', $progress->settled / max(0.001, $progress->elapsedSeconds)),
        ));
        $footer = $wrap('<fg=gray>' . ($status === 'RUNNING' ? 'Ctrl+C cancels; cleanup is required for PASS.' : 'Final evidence is in the saved report.') . '</>');
        $visible = count(self::STAGES);
        do {
            $checklist = $wrap($this->checklist($visible, $status));
            $message = $header . "\n" . $checklist . "\n\n" . $current;
            $lines = substr_count($message . "\nbar\n" . $unit . "\n" . $metrics . "\n" . $footer, "\n") + 1;
        } while ($lines >= $terminal->getHeight() && --$visible > 0);

        // Clear using the old layout before replacing it with a different number of rows.
        $bar->clear();
        $bar->setMessage($message);
        $bar->setMessage($counter, 'phase_progress');
        $bar->setMessage($unit, 'phase_unit');
        $bar->setMessage($metrics, 'metrics');
        $bar->setMessage($footer, 'footer');
        $bar->setBarCharacter('█');
        $bar->setEmptyBarCharacter('░');
        $bar->setProgressCharacter('▓');
        $bar->setBarWidth(max(1, min(32, $width - strlen($counter) - 1)));
        $bar->setMaxSteps($indeterminate ? 0 : $progress->phaseTotal);
        $bar->setFormat("%message%\n<fg={$color}>%bar%</> %phase_progress%\n%phase_unit%\n%metrics%\n%footer%");
        if ($this->phase !== $progress->phase) {
            $bar->start($indeterminate ? 0 : $progress->phaseTotal);
        }

        $bar->setProgress($passed ? $progress->phaseTotal : $progress->phaseCompleted ?? 0);
        $bar->display();
    }

    /**
     * Count completed stages equally, without pretending to estimate elapsed work or time remaining.
     */
    private function overallBar(int $width): string
    {
        $output = new BufferedOutput();
        $bar = new ProgressBar($output, count(self::STAGES));
        $bar->setFormat('%bar% %percent:3s%% of stages');
        $bar->setBarCharacter('█');
        $bar->setEmptyBarCharacter('░');
        $bar->setProgressCharacter('▓');
        $bar->setBarWidth(max(1, min(32, $width - 15)));
        $bar->setProgress(count($this->completedStages));
        $bar->display();

        return trim($output->fetch());
    }

    /**
     * Keep completed, active, failed and pending stages distinct, including repeated publication checks.
     */
    private function checklist(int $visible, string $status): string
    {
        $start = max(0, min($this->stage - 1, count(self::STAGES) - $visible));
        $rows = $visible < count(self::STAGES) ? [sprintf('Stages %d-%d/9 (compact)', $start + 1, $start + $visible)] : [];
        foreach (array_slice(self::STAGES, $start, $visible, preserve_keys: true) as $index => $label) {
            [$mark, $color] = match (true) {
                isset($this->completedStages[$index]) => ['x', 'green'],
                $index !== $this->stage => [' ', 'gray'],
                $status === 'FAILED' => ['!', 'red'],
                $status === 'CANCELLED' => ['!', 'red'],
                default => ['>', 'cyan'],
            };
            $rows[] = sprintf('<fg=%s>[%s] %d. %s</>', $color, $mark, $index + 1, $label);
        }

        return implode("\n", $rows);
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
     * Retain a final checklist without finishing a failed bar or inventing verification success.
     * Both the success status and confirmed fixture cleanup are required to mark every stage done.
     *
     * @param array{status: string, failure_type?: ?string, ...} $report
     */
    public function finish(array $report, bool $json): void
    {
        $passed = $report['status'] === 'passed' && ($report['cleanup'] ?? null) === 'completed';
        if ($this->bar !== null && $this->lastProgress !== null && ! $json) {
            if ($passed) {
                $this->completedStages = array_fill_keys(array_keys(self::STAGES), true);
            }

            $this->render($this->lastProgress, $passed ? 'PASSED' : ($report['interrupted'] ?? false ? 'CANCELLED' : 'FAILED'));
            fwrite(STDERR, "\n");
        } else {
            $this->bar?->clear();
        }

        if (is_string($report['report_file'] ?? null)) {
            fwrite(STDERR, 'Report: ' . $report['report_file'] . "\n");
        }

        if ($json) {
            $this->console->writeRaw(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");

            return;
        }

        $this->console->writeRaw(
            $passed
                ? "PASS: 100 Items preserved; both publications independently verified; stable inodes; corruption rejected; fixture cleaned.\n"
                : 'FAIL: ' . ($report['failure_type'] ?? 'unknown') . '; no successful acceptance claim. See the retained report and checkpoints.' . "\n",
        );
    }
}

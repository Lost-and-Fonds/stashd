<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use App\Development\DTO\FilesystemStressProgress;
use Closure;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

/**
 * Observe persisted application state and independent verification without preserving bytes.
 *
 * All writes to the Vault, Catalogue and Broadcast destination remain application
 * operations. The only data this observer creates are external metrics and its
 * private process-tree sampler. The sampler observes descendant RSS on Linux;
 * summing RSS counts shared pages more than once and is not proportional set size.
 */
final class FilesystemStressObserver
{
    private array $phases = [];
    private array $queueSamples = [];
    private int $last;
    private float $startedAt;
    private array $workerBatches = [];
    private array $admissionCalls = [];
    private string $reportName;
    private ?string $reportDirectory;
    private ?array $monitor = null;
    private float $runStarted;
    private float $lastRefresh = 0;
    private string $phase = 'setup';
    private ?string $run = null;
    private int $preserved = 0;
    private int $verified = 0;
    private ?int $peakRss = null;
    private ?int $lastActiveWorkers = null;
    private int $maxActiveWorkers = 0;
    private int $phaseCompleted = 0;
    private int $phaseTotal;
    private string $phaseUnit = 'Items';
    private ProcessTreeRssTrace $rssTrace;

    /**
     * Start one independent process-tree RSS sampler for the complete experiment.
     */
    public function __construct(
        private ArchiveFixture $archive,
        private PDO $database,
        private int $count,
        private ?Closure $observer = null,
        ?string $reportDirectory = null,
        ?string $reportName = null,
    ) {
        $this->reportName = $reportName ?? 'filesystem-stress-' . $count . '-' . bin2hex(random_bytes(5));
        $directory = $reportDirectory ?? getenv('STASHD_FILESYSTEM_STRESS_REPORT_DIR');
        $this->reportDirectory = is_string($directory) && $directory !== '' ? rtrim($directory, '/') : null;
        if ($this->reportDirectory !== null && ! is_dir($this->reportDirectory)) {
            throw new RuntimeException('The Filesystem stress report directory must already exist.');
        }

        $this->last = hrtime(true);
        $this->phaseTotal = $count;
        $this->rssTrace = new ProcessTreeRssTrace($archive->path('filesystem-stress-monitor.jsonl'));
        $this->runStarted = microtime(true);
        $this->startedAt = microtime(true);
        if (! is_dir('/proc')) {
            throw new RuntimeException('Filesystem stress requires Linux /proc for process-tree RSS evidence.');
        }

        $pipes = [];
        $process = proc_open(
            [
                'python3',
                'tools/fixtures/process_tree_rss.py',
                '--pid',
                (string) getmypid(),
                '--stop',
                $archive->path('filesystem-stress-monitor.stop'),
                '--output',
                $archive->path('filesystem-stress-monitor.json'),
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch independent Linux process-tree sampler.');
        }

        $this->monitor = ['process' => $process, 'pipes' => $pipes];
        try {
            $this->checkpoint('baseline');
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /**
     * Verify exact independent bytes, pinning the canonical reference only at 10,000.
     *
     * This process cannot inspect Stashd's database, Vault or operational Results.
     * A nonzero status is returned for the deliberate output-corruption control.
     * The caller displays diagnostics separately from the progress bar.
     */
    public static function verify(string $manifest, string $directory, int $count, Closure $diagnostic): int
    {
        $arguments = ['python3', 'tools/fixtures/microtext.py', 'verify', '--directory', $directory, '--manifest', $manifest];
        if ($count === 10_000) {
            $arguments = [...$arguments, '--reference', 'tests/fixtures/microtext/reference-v1.json'];
        }

        $pipes = [];
        $process = proc_open(
            $arguments,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch independent Microtext verifier.');
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $diagnostic((string) $output . ($exit !== 0 ? (string) $error : ''));

        return $exit;
    }

    /**
     * Select the Input run whose discovery, admission and settlement are being observed.
     */
    public function observeRun(string $run): void
    {
        $this->run = $run;
    }

    /**
     * Keep separately measured command and inspection costs for baseline comparisons.
     */
    public function admissionCall(string $run, float $admitSeconds, float $inspectionSeconds): void
    {
        $this->admissionCalls[] = ['run' => $run, 'admit_seconds' => $admitSeconds, 'inspection_seconds' => $inspectionSeconds];
    }

    /**
     * Record a real worker interval and its observed settled delta, not its message budget.
     */
    public function workerBatch(string $run, float $elapsed, float $inspectionSeconds, int $settledDelta): void
    {
        $this->workerBatches[] = ['run' => $run, 'elapsed_seconds' => $elapsed, 'inspection_seconds' => $inspectionSeconds, 'settled_delta' => $settledDelta];
    }

    /**
     * Record phase time, database size and persisted job/attempt counts.
     *
     * Missing pre-initialization tables are reported as null, never as zero.
     * All metrics are observations and cannot replace external correctness checks.
     */
    public function checkpoint(string $phase, array $facts = []): void
    {
        if ($phase === 'first_preservation_verified') {
            $this->preserved = $facts['items'];
        }

        if ($phase === 'source_independent_broadcast_verified') {
            $this->verified = $facts['reported_files'];
        }

        $now = hrtime(true);
        $endedAt = microtime(true);
        $tables = [
            'discovery_items',
            'acquisition_attempts',
            'operation_assignments',
            'operation_demands',
            'operation_attempts',
            'operation_results',
            'broadcast_reports',
            'messenger_messages',
            'durable_events',
            'durable_workflow_runs',
        ];
        $counts = [];
        foreach ($tables as $name) {
            $present = $this->database->prepare('SELECT to_regclass(?)');
            $present->execute(['configuration_proof.' . $name]);
            if ($present->fetchColumn() === null) {
                $counts[$name] = null;
                continue;
            }

            $counts[$name] = (int) $this->database->query('SELECT count(*) FROM configuration_proof.' . $name)->fetchColumn();
        }

        $attemptOutcomes = [];
        $retries = null;
        if ($counts['operation_attempts'] !== null) {
            $outcomes = $this->database->query('SELECT outcome, count(*) AS number FROM configuration_proof.operation_attempts GROUP BY outcome');
            foreach ($outcomes->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $attemptOutcomes[$row['outcome']] = (int) $row['number'];
            }

            $retries = (int) $this->database->query('SELECT count(*) - count(DISTINCT demand_id) FROM configuration_proof.operation_attempts')->fetchColumn();
        }

        $this->phases[] = [
            'phase' => $phase,
            'elapsed_seconds' => ($now - $this->last) / 1_000_000_000,
            'started_unix_seconds' => $this->startedAt,
            'ended_unix_seconds' => $endedAt,
            'effective_items_per_second' => in_array(
                $phase,
                [
                    'discovery_worker_settled',
                    'first_acquisition_settled',
                    'unchanged_discovery_complete',
                    'unchanged_acquisition_settled',
                    'broadcast_selection_frozen',
                    'broadcast_worker_settled',
                ],
                true,
            )
                ? $this->count / (($now - $this->last) / 1_000_000_000)
                : null,
            'database_bytes' => (int) $this->database->query('SELECT pg_database_size(current_database())')->fetchColumn(),
            'vault_files' => is_dir($this->archive->path('vault')) ? self::diskFiles($this->archive->path('vault')) : null,
            'persisted_counts' => $counts,
            'attempt_outcomes' => $attemptOutcomes,
            'extra_attempts_for_existing_demands' => $retries,
            'transport_queue_widths' => $counts['messenger_messages'] === null
                ? null
                : $this->database
                    ->query('SELECT queue_name, count(*) AS messages FROM configuration_proof.messenger_messages GROUP BY queue_name ORDER BY queue_name')
                    ->fetchAll(PDO::FETCH_ASSOC),
            'facts' => $facts,
        ];
        $this->phases[array_key_last($this->phases)]['measurement_seconds'] = (hrtime(true) - $now) / 1_000_000_000;
        if ($this->reportDirectory !== null) {
            // Keep completed checkpoints even when a fatal error cannot reach finally.
            $partial =
                json_encode([
                    'status' => 'incomplete',
                    'files' => $this->count,
                    'generated_at_utc' => gmdate('c'),
                    'phases' => $this->phases,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
            $path = $this->reportDirectory . '/' . $this->reportName . '.partial.json';
            Assert::assertSame(strlen($partial), file_put_contents($path . '.tmp', $partial));
            Assert::assertTrue(rename($path . '.tmp', $path));
        }

        $this->phases[array_key_last($this->phases)]['measurement_seconds'] = (hrtime(true) - $now) / 1_000_000_000;
        $this->last = hrtime(true);
        $this->startedAt = microtime(true);
        if (! in_array($phase, ['baseline', 'failed'], true)) {
            $this->refresh();
        }
    }

    /**
     * Reset progress for the next operation, naming what its counts actually measure.
     * Item phases default to the workload size; source removal and cleanup use one fixture.
     */
    public function phase(string $phase, string $unit, ?int $total = null): void
    {
        $this->phase = $phase;
        $this->phaseCompleted = 0;
        $this->phaseTotal = $total ?? $this->count;
        $this->phaseUnit = $unit;
        if (in_array($phase, ['discovery', 'unchanged_rescan', 'first_acquisition', 'unchanged_acquisition'], true)) {
            $this->run = null;
        }

        $this->lastRefresh = 0;
        $this->refresh();
    }

    /**
     * Advance the current phase only after its caller has actually checked the stated evidence.
     * This is phase progress, never permission to report an overall acceptance pass.
     */
    public function progress(int $completed): void
    {
        $this->phaseCompleted = $completed;
        $this->lastRefresh = 0;
        $this->refresh();
    }

    /**
     * Sample persisted state at most once a second while a supervised command runs.
     * Only completed physical checks advance preserved and externally verified counts.
     */
    public function refresh(int $activeWorkers = 0): void
    {
        if ($this->observer === null || $activeWorkers === $this->lastActiveWorkers && (microtime(true) - $this->lastRefresh) < 1) {
            return;
        }

        $this->lastActiveWorkers = $activeWorkers;
        $this->maxActiveWorkers = max($this->maxActiveWorkers, $activeWorkers);
        $this->lastRefresh = microtime(true);
        $present = $this->database->query("SELECT to_regclass('configuration_proof.discovery_items')")->fetchColumn();
        $discovered = $present === null ? null : (int) $this->database->query('SELECT count(DISTINCT source_id) FROM configuration_proof.discovery_items')->fetchColumn();
        $admitted = null;
        $settled = null;
        $outstanding = null;
        $failed = null;
        if ($this->database->query("SELECT to_regclass('configuration_proof.operation_assignments')")->fetchColumn() !== null) {
            $outstanding = (int) $this->database->query('SELECT count(*) FROM configuration_proof.operation_assignments WHERE active')->fetchColumn();
            $failed = (int) $this->database->query('SELECT count(*) FROM configuration_proof.operation_assignments WHERE error IS NOT NULL')->fetchColumn();
            if ($this->run !== null) {
                $query = $this->database->prepare(<<<'SQL'
                SELECT count(DISTINCT payload::jsonb ->> 'sourceId') AS admitted,
                    count(DISTINCT payload::jsonb ->> 'sourceId') FILTER (WHERE satisfied) AS settled
                FROM configuration_proof.operation_demands WHERE payload::jsonb ->> 'runId' = ?
                SQL);
                $query->execute([$this->run]);
                $row = $query->fetch(PDO::FETCH_ASSOC);
                $admitted = (int) $row['admitted'];
                $settled = (int) $row['settled'];
            }
        }

        $phaseCompleted = match ($this->phase) {
            'discovery' => $discovered,
            'first_acquisition', 'unchanged_acquisition' => $settled,
            default => $this->phaseCompleted,
        };
        if ($this->phase === 'unchanged_rescan' && $this->run !== null && $present !== null) {
            $query = $this->database->prepare('SELECT count(DISTINCT source_id) FROM configuration_proof.discovery_items WHERE run_id = ?');
            $query->execute([$this->run]);
            $phaseCompleted = (int) $query->fetchColumn();
        }

        $this->peakRss = $this->rssTrace->peak();

        ($this->observer)(new FilesystemStressProgress(
            $this->phase,
            microtime(true) - $this->runStarted,
            $discovered,
            $admitted,
            $settled,
            $this->preserved,
            $this->verified,
            $outstanding,
            $failed,
            $activeWorkers,
            $this->peakRss,
            $phaseCompleted,
            $this->phaseTotal,
            $this->phaseUnit,
        ));
    }

    /**
     * Save the observed admission width; this is not a fabricated instantaneous peak.
     */
    public function observeQueue(array $state): void
    {
        $this->queueSamples[] = [
            'run' => $state['run_id'],
            'admitted' => $state['admitted'],
            'settled' => $state['settled'],
            'outstanding' => $state['outstanding'],
            'failed' => $state['failed'],
            'transport_queues' => $this->database
                ->query('SELECT queue_name, count(*) AS messages FROM configuration_proof.messenger_messages GROUP BY queue_name ORDER BY queue_name')
                ->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Finish the sampler and write one machine-readable report outside source A.
     *
     * Set STASHD_FILESYSTEM_STRESS_REPORT_DIR to an existing directory to retain the report
     * after the disposable test Vault and database are safely destroyed.
     */
    public function finish(string $vaultRoot, ?string $failure = null, bool $pendingCleanup = false): array
    {
        $this->close();
        $sample = $this->archive->path('filesystem-stress-monitor.json');
        Assert::assertFileExists($sample);
        $rss = json_decode((string) file_get_contents($sample), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertGreaterThan(0, $rss['samples'] ?? 0, 'Process memory was never actually sampled.');
        Assert::assertGreaterThan(0, $rss['peak_total_rss_kib'] ?? 0, 'The RSS sampler did not observe a running process.');
        $trace = file($this->archive->path('filesystem-stress-monitor.jsonl'), FILE_IGNORE_NEW_LINES);
        Assert::assertIsArray($trace);
        foreach ($this->phases as &$phase) {
            $peak = null;
            foreach ($trace as $line) {
                $point = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($point['unix_seconds'] >= $phase['started_unix_seconds'] && $point['unix_seconds'] <= $phase['ended_unix_seconds']) {
                    $peak = max($peak ?? 0, $point['rss_kib']);
                }
            }

            $phase['observed_peak_process_tree_rss_kib'] = $peak;
        }

        unset($phase);
        $maxOutstanding = 0;
        foreach ($this->queueSamples as $state) {
            $maxOutstanding = max($maxOutstanding, $state['outstanding']);
        }

        $report = [
            'format' => 'stashd-filesystem-stress-1',
            'generated_at_utc' => gmdate('c'),
            'files' => $this->count,
            'reference_pinned' => $this->count === 10_000,
            'status' => $failure === null ? ($pendingCleanup ? 'incomplete' : 'passed') : 'failed',
            'failure_type' => $failure,
            'rss' => $rss,
            'sampled_max_outstanding' => $maxOutstanding,
            'queue_samples' => count($this->queueSamples),
            'admission_samples' => $this->queueSamples,
            'acquisition_worker_batches' => $this->workerBatches,
            'observed_max_active_workers' => $this->maxActiveWorkers,
            'acquisition_admission_calls' => $this->admissionCalls,
            'vault_files' => is_dir($vaultRoot) ? self::diskFiles($vaultRoot) : null,
            'phases' => $this->phases,
            'note' => 'RSS sums separate process resident sets (shared pages may double-count); admission depth is sampled, not an instantaneous transport maximum.',
        ];
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        $name = $this->reportName . '.json';
        $local = $this->archive->path($name);
        Assert::assertSame(strlen($json), file_put_contents($local, $json));

        if (! $pendingCleanup) {
            $this->publish($report);
        }

        return $report;
    }

    /**
     * Atomically save final evidence only after fixture cleanup has also succeeded.
     */
    public function publish(array $report): void
    {
        if ($this->reportDirectory === null) {
            return;
        }

        $path = $this->reportDirectory . '/' . $this->reportName . '.json';
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        Assert::assertSame(strlen($json), file_put_contents($path . '.tmp', $json));
        Assert::assertTrue(rename($path . '.tmp', $path));
        $partial = $this->reportDirectory . '/' . $this->reportName . '.partial.json';
        if (is_file($partial)) {
            Assert::assertTrue(unlink($partial));
        }
    }

    /**
     * Stop and reap the sampler on success or test failure, without leaving workers.
     */
    public function close(): void
    {
        if ($this->monitor === null) {
            return;
        }

        file_put_contents($this->archive->path('filesystem-stress-monitor.stop'), '');
        $process = $this->monitor['process'];
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }

        foreach ($this->monitor['pipes'] as $pipe) {
            if (! is_resource($pipe)) {
                continue;
            }

            fclose($pipe);
        }

        proc_close($process);
        $this->monitor = null;
    }

    /**
     * Measure a Vault's logical file sizes and allocated blocks at experiment end.
     *
     * These bytes include OCFL objects and local custody evidence, not just payloads.
     */
    private static function diskFiles(string $root): array
    {
        $files = 0;
        $logical = 0;
        $allocated = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (! $entry->isFile() || $entry->isLink()) {
                continue;
            }

            $stat = $entry->getFileInfo();
            $files++;
            $logical += $stat->getSize();
            $physical = lstat($stat->getPathname());
            if ($physical !== false) {
                $allocated += $physical['blocks'] * 512;
            }
        }

        return ['count' => $files, 'logical_bytes' => $logical, 'allocated_block_bytes' => $allocated];
    }
}

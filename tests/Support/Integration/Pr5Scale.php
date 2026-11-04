<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use FilesystemIterator;
use PDO;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

use function configuration_cli;

/**
 * Drive the real scale workflow and collect evidence without copying or preserving bytes.
 *
 * All writes to the Vault, Catalogue and Broadcast destination remain application
 * operations. The only data this observer creates are external metrics and its
 * private process-tree sampler. The sampler observes descendant RSS on Linux;
 * summing RSS counts shared pages more than once and is not proportional set size.
 */
final class Pr5Scale
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

    /**
     * Start one independent process-tree RSS sampler for the complete experiment.
     */
    public function __construct(
        private ArchiveFixture $archive,
        private PDO $database,
        private int $count,
    ) {
        $this->reportName = 'pr5-' . $count . '-' . bin2hex(random_bytes(5));
        $directory = getenv('STASHD_PR5_REPORT_DIR');
        $this->reportDirectory = is_string($directory) && $directory !== '' ? rtrim($directory, '/') : null;
        if ($this->reportDirectory !== null && ! is_dir($this->reportDirectory)) {
            throw new RuntimeException('STASHD_PR5_REPORT_DIR must already exist.');
        }

        $this->last = hrtime(true);
        $this->startedAt = microtime(true);
        if (! is_dir('/proc')) {
            throw new RuntimeException('Measured PR 5 stress requires Linux /proc for process-tree RSS evidence.');
        }

        $pipes = [];
        $process = proc_open(
            [
                'python3',
                'tools/fixtures/process_tree_rss.py',
                '--pid',
                (string) getmypid(),
                '--stop',
                $archive->path('pr5-monitor.stop'),
                '--output',
                $archive->path('pr5-monitor.json'),
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch independent Linux process-tree sampler.');
        }

        $this->monitor = ['process' => $process, 'pipes' => $pipes];
        $this->checkpoint('baseline');
    }

    /**
     * Verify exact independent bytes, pinning the canonical reference only at 10,000.
     *
     * This process cannot inspect Stashd's database, Vault or operational Results.
     * A nonzero status is returned for the deliberate output-corruption control.
     */
    public static function verify(string $manifest, string $directory, int $count): int
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
        file_put_contents('php://stderr', (string) $output);
        if ($exit !== 0) {
            file_put_contents('php://stderr', (string) $error);
        }

        return $exit;
    }

    /**
     * Refill at most 100 active per-file assignments and drain them through
     * the actual durable Operations worker in batches rather than 10,000 CLI boots.
     *
     * Observed outstanding counts are samples, not a continuous queue-depth trace.
     * A deadline prevents infinite loops; it is not a throughput objective.
     */
    public static function acquire(
        ArchiveFixture $archive,
        array $descriptor,
        string $run,
        int $count,
        self $metrics,
        int $seconds = 14_400,
    ): void {
        $deadline = microtime(true) + $seconds;
        $state = [];
        do {
            $started = hrtime(true);
            $admitted = Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $run]);
            Assert::assertNotEmpty($admitted['assignment_id'] ?? null);
            $admitSeconds = (hrtime(true) - $started) / 1_000_000_000;
            $started = hrtime(true);
            $state = Pr2Directory::admission($archive, $descriptor, $run, $count);
            $metrics->admissionCalls[] = ['run' => $run, 'admit_seconds' => $admitSeconds, 'inspection_seconds' => (hrtime(true) - $started) / 1_000_000_000];
            $metrics->observeQueue($state);
            if ($state['status'] === 'complete') {
                break;
            }

            $started = hrtime(true);
            $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once', '--limit=100']);
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            Assert::assertSame(0, $worker['code'], $worker['output'] . $worker['errors']);
            $before = $state['settled'];
            $started = hrtime(true);
            $state = Pr2Directory::admission($archive, $descriptor, $run, $count);
            $metrics->workerBatches[] = [
                'run' => $run,
                'elapsed_seconds' => $elapsed,
                'inspection_seconds' => (hrtime(true) - $started) / 1_000_000_000,
                'settled_delta' => $state['settled'] - $before,
            ];
            Assert::assertLessThanOrEqual(100, $state['settled'] - $before, 'The message limit must bound each drain.');
            $metrics->observeQueue($state);
            Assert::assertNotSame('failed', $state['status'], 'The actual acquisition run failed.');
        } while (microtime(true) < $deadline);

        Assert::assertSame('complete', $state['status'] ?? null, 'Timed out before all per-file Jobs settled.');
        Assert::assertSame($count, $state['admitted']);
        Assert::assertSame($count, $state['settled']);
        Assert::assertSame(0, $state['outstanding']);
        Assert::assertSame(0, $state['failed']);
    }

    /**
     * Publish the Stash's real frozen selection using an Operations Job, then
     * exhaust the bounded publication-report pages without inventing coverage.
     *
     * The caller must separately verify exact destination files and OCFL state.
     *
     * @return array{broadcast: string, run: string}
     */
    public static function broadcast(
        ArchiveFixture $archive,
        array $descriptor,
        string $stash,
        int $count,
        ?string $broadcast = null,
        ?self $metrics = null,
    ): array {
        if ($broadcast === null) {
            $configured = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
            $broadcast = $configured['id'];
        }

        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $stash, $broadcast]);
        $metrics?->checkpoint('broadcast_selection_frozen');
        $receipt = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']]);
        Assert::assertNotEmpty($receipt['assignment_id'] ?? null);
        Pr2Directory::settle($archive, $descriptor, $receipt['assignment_id'], 3_600);
        $metrics?->checkpoint('broadcast_worker_settled');

        $after = -1;
        $seen = [];
        do {
            $args = ['broadcast:publication', $frozen['id']];
            if ($after >= 0) {
                $args[] = (string) $after;
            }

            $page = Pr1RoundTrip::command($archive, $descriptor, $args);
            Assert::assertSame('complete', $page['run']['status']);
            Assert::assertLessThanOrEqual(100, count($page['results']));
            foreach ($page['results'] as $record) {
                Assert::assertSame('file', $record['kind']);
                Assert::assertGreaterThan($after, (int) $record['position']);
                $after = (int) $record['position'];
                Assert::assertArrayNotHasKey($after, $seen, 'A published file report position must be unique.');
                $seen[$after] = true;
            }
        } while ($page['results'] !== []);

        Assert::assertCount($count, $seen, 'One real report is required for every preserved Item.');

        return ['broadcast' => $broadcast, 'run' => $frozen['id']];
    }

    /**
     * Record phase time, database size and persisted job/attempt counts.
     *
     * Missing pre-initialization tables are reported as null, never as zero.
     * All metrics are observations and cannot replace external correctness checks.
     */
    public function checkpoint(string $phase, array $facts = []): void
    {
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
     * Set STASHD_PR5_REPORT_DIR to an existing directory to retain the report
     * after the disposable test Vault and database are safely destroyed.
     */
    public function finish(string $vaultRoot, ?string $failure = null): void
    {
        $this->close();
        $sample = $this->archive->path('pr5-monitor.json');
        Assert::assertFileExists($sample);
        $rss = json_decode((string) file_get_contents($sample), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertGreaterThan(0, $rss['samples'] ?? 0, 'Process memory was never actually sampled.');
        Assert::assertGreaterThan(0, $rss['peak_total_rss_kib'] ?? 0, 'The RSS sampler did not observe a running process.');
        $trace = file($this->archive->path('pr5-monitor.jsonl'), FILE_IGNORE_NEW_LINES);
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
            'format' => 'stashd-pr5-stress-1',
            'generated_at_utc' => gmdate('c'),
            'files' => $this->count,
            'reference_pinned' => $this->count === 10_000,
            'status' => $failure === null ? 'passed' : 'failed',
            'failure_type' => $failure,
            'rss' => $rss,
            'sampled_max_outstanding' => $maxOutstanding,
            'queue_samples' => count($this->queueSamples),
            'admission_samples' => $this->queueSamples,
            'acquisition_worker_batches' => $this->workerBatches,
            'acquisition_admission_calls' => $this->admissionCalls,
            'vault_files' => is_dir($vaultRoot) ? self::diskFiles($vaultRoot) : null,
            'phases' => $this->phases,
            'note' => 'RSS sums separate process resident sets (shared pages may double-count); admission depth is sampled, not an instantaneous transport maximum.',
        ];
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        $name = $this->reportName . '.json';
        $local = $this->archive->path($name);
        Assert::assertSame(strlen($json), file_put_contents($local, $json));

        if ($this->reportDirectory !== null) {
            Assert::assertTrue(copy($local, $this->reportDirectory . '/' . $name));
            Assert::assertTrue(unlink($this->reportDirectory . '/' . $this->reportName . '.partial.json'));
        }

        file_put_contents('php://stderr', 'PR5_METRICS ' . $json);
    }

    /**
     * Stop and reap the sampler on success or test failure, without leaving workers.
     */
    public function close(): void
    {
        if ($this->monitor === null) {
            return;
        }

        file_put_contents($this->archive->path('pr5-monitor.stop'), '');
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

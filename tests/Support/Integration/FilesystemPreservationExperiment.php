<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use App\Operations\OperationsWorkerPool;
use Closure;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe;
use Throwable;

/**
 * Run only source preparation, discovery and independently verified preservation in a disposable fixture.
 * Presentation wraps stages but never decides Job completion or preservation correctness.
 */
final class FilesystemPreservationExperiment
{
    private ArchiveFixture $archive;
    private ?PDO $database = null;
    private array $descriptor = [];
    private array $fixture;
    private bool $created = false;
    private bool $cancelled = false;
    private OperationsWorkerPool $pool;
    private string $path;
    private array $report;
    private ?Closure $progress = null;
    private ?string $run = null;
    private ?string $input = null;
    private float $lastSample = 0;
    private bool $stopped = false;
    private bool $admitting = false;

    /**
     * Validate workload/report boundaries before allocating any disposable resources.
     * The observer is a read-only evidence hook used for independent failure/interrupt tests.
     */
    public function __construct(
        private int $count,
        private int $workers,
        string $directory,
        private ?Closure $presentation = null,
        private ?Closure $observer = null,
    ) {
        if ($count < 1 || $count > 10_000 || $workers < 1 || $workers > 20 || ! is_dir($directory) || ! is_writable($directory)) {
            throw new InvalidArgumentException('Use 1–10000 files, 1–20 workers and an existing writable report directory.');
        }

        $this->path = rtrim($directory, '/') . '/filesystem-preservation-' . bin2hex(random_bytes(8)) . '.json';
        $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
        $this->fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
        $this->pool = new OperationsWorkerPool(new GenericProcessExecutor());
        $this->report = [
            'format' => 'stashd-filesystem-preservation-1',
            'files' => $count,
            'workers' => $workers,
            'status' => 'incomplete',
            'scope' => 'preservation-only',
            'phase' => 'setup',
            'phases' => [],
            'admitted' => 0,
            'completed_jobs' => 0,
            'verified_items' => 0,
            'verified_assets' => 0,
            'worker_pids' => [],
            'max_observed_acquiring_workers' => 0,
            'overlap_witness' => [],
            'sampled_max_outstanding' => 0,
            'sampled_peak_owner_and_worker_rss_kib' => 0,
            'cleanup_order' => [],
            'report_file' => basename($this->path),
            'owner_pid' => getmypid(),
            'generated_at_utc' => gmdate('c'),
            'php' => PHP_VERSION,
            'prompts' => \Composer\InstalledVersions::getPrettyVersion('laravel/prompts'),
        ];
        foreach ([
            'app/Development/FilesystemPreservationCommand.php',
            'app/Development/FilesystemStressTask.php',
            'app/Operations/OperationsWorkerPool.php',
            'app/Operations/OperationsWorkerStartupException.php',
            'app/Integration/AcquisitionAdmission.php',
            'app/Integration/AcquisitionCoordinator.php',
            'app/Integration/DiscoveryStore.php',
            'tests/Support/Integration/FilesystemPreservationExperiment.php',
            'tests/Support/Integration/PreservationWitness.php',
            'tests/Support/Integration/filesystem-worker.php',
            'tools/fixtures/microtext.py',
            'composer.lock',
        ] as $file) {
            $this->report['code_sha256'][$file] = hash_file('sha256', dirname(__DIR__, 3) . '/' . $file);
        }
    }

    /**
     * Keep cancellation in the owner and preserve partial evidence through teardown on every ordinary failure.
     * Never remove database/storage if owned worker termination cannot be confirmed.
     */
    public function run(): array
    {
        require_once __DIR__ . '/configuration-cli.php';
        $adapter = getenv('STASHD_TRANSACTION_TEST_ADAPTER');
        putenv('STASHD_TRANSACTION_TEST_ADAPTER=' . dirname(__DIR__) . '/RecordsAndArchives/transaction-adapter.php');
        $async = pcntl_async_signals(true);
        $handlers = [SIGINT => pcntl_signal_get_handler(SIGINT), SIGTERM => pcntl_signal_get_handler(SIGTERM)];
        foreach ($handlers as $signal => $_) {
            pcntl_signal($signal, function (): void {
                $this->cancelled = true;
            });
        }

        $started = hrtime(true);
        $failure = null;
        try {
            try {
                \PHPUnit\TextUI\Configuration\Registry::get();
            } catch (\AssertionError|\TypeError) {
                // Standalone CLI witnesses need PHPUnit's assertion configuration, not a test run.
                new \PHPUnit\TextUI\Configuration\Builder()->build(['filesystem-preservation', '--no-configuration']);
            }

            $this->save();
            $this->archive = new ArchiveFixture();
            $this->report['fixture_root'] = dirname($this->archive->path('vault'));
            $this->report['fixture_database'] = $this->fixture['database'];
            $this->descriptor = TransactionProbe::run($this->report['fixture_root'], ['operation' => 'projection.describe', 'fixture' => $this->fixture]);
            $this->created = true;
            $this->database = ProjectionProbe::connect($this->descriptor, $this->fixture['database']);
            $this->archive->onProcessTick = $this->tick(...);
            $this->command(['vault:init']);
            $this->command(['integration:init']);
            $corpus = $this->archive->path('inputs');
            $manifest = $this->archive->path('manifest.json');
            $this->phase('prepare', function () use ($corpus, $manifest): void {
                $this->execute([
                    'python3',
                    'tools/fixtures/microtext.py',
                    'generate',
                    '--directory',
                    $corpus,
                    '--manifest',
                    $manifest,
                    '--count',
                    (string) $this->count,
                    '--progress',
                ], 'Generating microtext');
                $this->execute(['python3', 'tools/fixtures/microtext.py', 'verify', '--directory', $corpus, '--manifest', $manifest, '--progress'], 'Checking source bytes');
                $this->report['manifest_sha256'] = hash_file('sha256', $manifest);
            });
            $stash = null;
            $this->phase('discover', function () use ($corpus, &$stash): void {
                $stash = $this->command(['stash:create', 'Filesystem preservation experiment'])['id'];
                $this->input = $this->command(['input:create', 'filesystem', $corpus])['id'];
                $this->command(['stash:input:attach', $stash, $this->input]);
                $this->pool->start($this->pending(['operations:work', '--notify-ready'], worker: true), $this->workers, $this->tick(...));
                $this->report['worker_pids'] = $this->pool->pids();
                $discovery = $this->command(['input:discover', $this->input]);
                $this->run = $discovery['run_id'];
                $this->report['discovery_run'] = $this->run;
                $deadline = hrtime(true) + 300_000_000_000;
                do {
                    $this->tick();
                    $state = $this->command(['operations:inspect', $discovery['assignment_id']]);
                    if ($state['state'] === 'failed' || hrtime(true) > $deadline) {
                        throw new RuntimeException('Discovery did not complete successfully.');
                    }

                    usleep(50_000);
                } while ($state['state'] !== 'completed');

                $scan = $this->command(['input:discovery', $this->run]);
                Assert::assertSame('finished', $scan['status']);
                Assert::assertSame('exhaustive', $scan['coverage']['kind']);
                Assert::assertSame($this->count, $scan['discovered_count']);
                $this->report['discovered'] = $scan['discovered_count'];
            });
            $this->phase('preserve', function () use ($manifest, &$stash): void {
                // The producer runs once to exhaustion while the same consumers remain alive.
                $admissionStarted = hrtime(true);
                $this->admitting = true;
                try {
                    $this->command(['input:acquire', $this->run]);
                } finally {
                    $this->admitting = false;
                }

                $this->report['admission_seconds'] = (hrtime(true) - $admissionStarted) / 1e9;
                $this->report['completed_jobs_at_admission_return'] = $this->report['completed_jobs'];
                $deadline = hrtime(true) + 600_000_000_000;
                do {
                    $this->tick();
                    if (hrtime(true) > $deadline) {
                        throw new RuntimeException('Preservation Jobs did not complete before the deadline.');
                    }

                    usleep(20_000);
                } while ($this->report['completed_jobs'] !== $this->count);

                $state = $this->command(['input:admission', $this->run]);
                Assert::assertSame('complete', $state['status']);
                Assert::assertSame($this->count, $state['admitted']);
                Assert::assertSame($this->count, $state['settled']);
                Assert::assertSame(0, $state['outstanding']);
                Assert::assertSame(0, $state['failed']);
                PreservationWitness::assignments($this->database, $this->run, $this->count);
                $results = $this->database->prepare(<<<'SQL'
                SELECT t.source_id, t.assignment_id, d.uuid AS demand_id, d.satisfied, r.attempt_id,
                    r.coverage::jsonb ->> 'status' AS coverage_status
                FROM configuration_proof.acquisition_attempts t
                JOIN configuration_proof.operation_demands d ON d.assignment_id = t.assignment_id
                JOIN configuration_proof.operation_results r ON r.demand_id = d.uuid
                WHERE t.run_id = ? ORDER BY t.source_id
                SQL);
                $results->execute([$this->run]);
                $this->report['completion_receipts'] = $results->fetchAll(PDO::FETCH_ASSOC);
                Assert::assertCount($this->count, $this->report['completion_receipts']);
                foreach ($this->report['completion_receipts'] as $receipt) {
                    Assert::assertTrue($receipt['satisfied']);
                    Assert::assertSame('preserved', $receipt['coverage_status']);
                }

                $this->report['admission'] = array_intersect_key($state, array_flip(['total', 'admitted', 'settled', 'outstanding', 'failed', 'cursor', 'status']));
                $this->pool->stop();
                $this->stopped = true;
                $entries = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['entries'];
                $entries = array_column($entries, null, 'path');
                $members = PreservationWitness::members($this->archive, $this->descriptor, $stash);
                $references = PreservationWitness::verify($this->archive, $members, $entries, function (int $verified): void {
                    $this->report['verified_items'] = $verified;
                    $this->report['verified_assets'] = $verified;
                    $this->update($verified, 'Verify Items/Assets + bytes');
                });
                Assert::assertCount($this->count, $references);
                $this->report['preservation_witness_sha256'] = hash('sha256', json_encode($references, JSON_THROW_ON_ERROR));
                $this->report['preservation_witnesses'] = $references;
                $this->report['database_bytes'] = (int) $this->database->query('SELECT pg_database_size(current_database())')->fetchColumn();
            });
        } catch (Throwable $error) {
            $failure = $error;
            $this->report['failure_type'] = $error::class;
            $this->report['failure_location'] = basename($error->getFile()) . ':' . $error->getLine();
            $this->report['failure_message'] = str_replace(
                array_values(array_intersect_key($this->descriptor, array_flip(['dsn', 'username', 'password']))),
                '[redacted]',
                $error->getMessage(),
            );
            $this->report['failed_phase'] = $this->report['phase'];
        } finally {
            try {
                $this->phase('cleanup', function (): void {
                    $this->pool->stop();
                    foreach ($this->pool->pids() as $pid) {
                        if (is_dir('/proc/' . $pid)) {
                            throw new RuntimeException('Owned worker was not reaped.');
                        }
                    }

                    $this->report['worker_pids'] = $this->pool->pids();
                    $this->report['cleanup_order'][] = 'workers_stopped_and_reaped';
                    if ($this->database !== null && $this->run !== null) {
                        $outcomes = $this->database->prepare(
                            'SELECT o.uuid AS attempt_id, o.backend_pid, o.outcome, o.started_at, o.ended_at, t.source_id FROM configuration_proof.operation_attempts o JOIN configuration_proof.acquisition_attempts t ON t.assignment_id = o.assignment_id WHERE t.run_id = ? ORDER BY o.started_at',
                        );
                        $outcomes->execute([$this->run]);
                        $this->report['attempt_history'] = $outcomes->fetchAll(PDO::FETCH_ASSOC);
                        $completed = $this->database->prepare(
                            'SELECT count(*) FROM configuration_proof.acquisition_attempts t JOIN configuration_proof.operation_assignments a ON a.uuid = t.assignment_id WHERE t.run_id = ? AND NOT a.active AND a.error IS NULL',
                        );
                        $completed->execute([$this->run]);
                        $this->report['completed_jobs_at_shutdown'] = (int) $completed->fetchColumn();
                    }

                    $this->database = null;
                    if ($this->created) {
                        $this->archive->assertOwned();
                        TransactionProbe::run($this->report['fixture_root'], ['operation' => 'projection.dispose', 'fixture' => $this->fixture]);
                        $admin = json_decode(file_get_contents(dirname(__DIR__, 3) . '/tmp/transaction-projection-admin.json'), true, flags: JSON_THROW_ON_ERROR);
                        $independent = new PDO($admin['dsn'], $admin['username'], $admin['password']);
                        foreach (['SELECT count(*) FROM pg_database WHERE datname = ?', 'SELECT count(*) FROM pg_roles WHERE rolname = ?'] as $sql) {
                            $absent = $independent->prepare($sql);
                            $absent->execute([$this->fixture['database']]);
                            Assert::assertSame(0, (int) $absent->fetchColumn());
                        }

                        $this->report['database_and_role_independently_absent'] = true;
                        $this->report['cleanup_order'][] = 'database_disposed';
                    }

                    if (isset($this->archive)) {
                        $this->archive->onProcessTick = null;
                        $this->archive->dispose();
                        Assert::assertFalse(is_dir($this->report['fixture_root']));
                        $this->report['cleanup_order'][] = 'storage_disposed';
                    }

                    $this->report['cleanup'] = 'completed';
                });
            } catch (Throwable $cleanup) {
                $failure ??= $cleanup;
                $this->report['cleanup'] = 'not_confirmed';
                $this->report['cleanup_failure_type'] = $cleanup::class;
                $this->report['cleanup_failure_message'] = str_replace(
                    array_values(array_intersect_key($this->descriptor, array_flip(['dsn', 'username', 'password']))),
                    '[redacted]',
                    $cleanup->getMessage(),
                );
            }

            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            pcntl_async_signals($async);
            putenv($adapter === false ? 'STASHD_TRANSACTION_TEST_ADAPTER' : 'STASHD_TRANSACTION_TEST_ADAPTER=' . $adapter);
        }

        $this->report['status'] = $failure === null && ! $this->cancelled ? 'passed' : 'failed';
        $this->report['cancelled'] = $this->cancelled;
        $this->report['elapsed_seconds'] = (hrtime(true) - $started) / 1e9;
        $this->save();

        return $this->report;
    }

    /**
     * Wrap an execution stage without letting presentation fabricate completion or verification.
     */
    private function phase(string $stage, Closure $work): void
    {
        $this->report['phase'] = $stage;
        $started = hrtime(true);
        try {
            $execute = function (Closure $progress) use ($work): void {
                $this->progress = $progress;
                $work();
            };
            if ($this->presentation !== null) {
                ($this->presentation)($stage, $execute);
            } else {
                $execute(static function (): void {});
            }
        } finally {
            $this->progress = null;
            $this->report['phases'][$stage] = ['seconds' => (hrtime(true) - $started) / 1e9, 'worker_pids' => $this->pool->pids()];
            $this->save();
        }
    }

    /**
     * Update the current task from observed outcomes, then retain a failure-injection witness if supplied.
     */
    private function update(int $completed, string $activity): void
    {
        $this->progress?->__invoke($completed, $this->count, $activity);
        $this->report['progress'][$activity] = $completed;
        $this->observer?->__invoke($this->report);
        if ($this->cancelled && $this->report['phase'] !== 'cleanup') {
            throw new RuntimeException('Filesystem experiment cancelled.');
        }

        $this->save();
    }

    /**
     * Sample run-scoped durable completion and simultaneous source-lock holders inside real acquisition.
     * Waiting workers, queue emptiness and handler start timestamps do not establish overlap here.
     */
    private function tick(): void
    {
        $this->report['worker_pids'] = $this->pool->pids();
        if (! $this->stopped) {
            $this->pool->assertRunning();
        }

        if ($this->cancelled) {
            throw new RuntimeException('Filesystem experiment cancelled.');
        }

        if ($this->stopped || $this->run === null || $this->database === null || (microtime(true) - $this->lastSample) < 0.05) {
            return;
        }

        $this->lastSample = microtime(true);
        $rss = 0;
        foreach ([getmypid(), ...$this->pool->pids()] as $pid) {
            $status = @file_get_contents('/proc/' . $pid . '/status');
            if (is_string($status) && preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $match)) {
                $rss += (int) $match[1];
            }
        }

        $this->report['sampled_peak_owner_and_worker_rss_kib'] = max($this->report['sampled_peak_owner_and_worker_rss_kib'], $rss);
        $query = $this->database->prepare(
            "SELECT count(a.uuid) AS admitted, count(a.uuid) FILTER (WHERE NOT a.active AND a.error IS NULL) AS completed, (SELECT count(*) FROM configuration_proof.operation_attempts o JOIN configuration_proof.acquisition_attempts t ON t.assignment_id = o.assignment_id WHERE t.run_id = ? AND o.outcome = 'failed') AS failed_attempts FROM configuration_proof.acquisition_attempts t LEFT JOIN configuration_proof.operation_assignments a ON a.uuid = t.assignment_id WHERE t.run_id = ?",
        );
        $query->execute([$this->run, $this->run]);
        $state = $query->fetch(PDO::FETCH_ASSOC);
        $this->report['admitted'] = (int) $state['admitted'];
        $this->report['completed_jobs'] = (int) $state['completed'];
        $this->report['failed_attempts'] = (int) $state['failed_attempts'];
        $outstanding = $this->report['admitted'] - $this->report['completed_jobs'];
        $this->report['sampled_max_outstanding'] = max($this->report['sampled_max_outstanding'], $outstanding);
        $locks = $this->database->prepare(<<<'SQL'
        SELECT DISTINCT l.pid AS backend_pid, s.application_name, t.source_id, t.assignment_id, o.uuid AS attempt_id, o.backend_pid AS authority_backend_pid
        FROM pg_locks l JOIN pg_stat_activity s ON s.pid = l.pid
        JOIN pg_stat_activity authority_session ON authority_session.application_name = s.application_name AND s.application_name LIKE 'stashd-filesystem-worker:%'
        JOIN configuration_proof.acquisition_attempts t ON l.objid::bigint = (hashtext('acquire:' || ? || ':' || t.source_id)::bigint & 4294967295)
        JOIN configuration_proof.operation_attempts o ON o.assignment_id = t.assignment_id AND o.backend_pid = authority_session.pid AND o.outcome = 'running'
        WHERE t.run_id = ? AND l.locktype = 'advisory' AND l.granted AND l.mode = 'ExclusiveLock' AND l.objsubid = 2
          AND l.classid::bigint = (hashtext('configuration_proof')::bigint & 4294967295)
          AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database())
        SQL);
        $locks->execute([$this->input, $this->run]);
        $active = $locks->fetchAll(PDO::FETCH_ASSOC);
        if ($this->admitting && $active !== [] && ! isset($this->report['producer_consumer_overlap'])) {
            $this->report['producer_consumer_overlap'] = ['observed_at' => microtime(true), 'acquisitions' => $active];
        }

        if (count($active) > $this->report['max_observed_acquiring_workers']) {
            $this->report['max_observed_acquiring_workers'] = count($active);
            $this->report['overlap_witness'] = ['observed_at' => microtime(true), 'acquisitions' => $active];
        }

        $found = $this->database->prepare('SELECT count(*) FROM configuration_proof.discovery_items WHERE run_id = ?');
        $found->execute([$this->run]);
        $this->update(
            $this->report['phase'] === 'discover' ? (int) $found->fetchColumn() : $this->report['completed_jobs'],
            $this->report['phase'] === 'discover' ? 'Discovering files' : 'Jobs completed (not verified)',
        );
        if ($this->report['failed_attempts'] > 0) {
            throw new RuntimeException('An acquisition handler failed; inspect retained attempt history.');
        }
    }

    /**
     * Build a Tempest command with only this fixture's operational credentials and Vault root.
     */
    private function pending(array $arguments, bool $worker = false): PendingProcess
    {
        return new PendingProcess(
            command: [
                PHP_BINARY,
                '-d',
                'lerd.devtools_flag=/disabled-for-filesystem-experiment',
                $worker ? 'tests/Support/Integration/filesystem-worker.php' : 'tempest',
                ...$arguments,
                '--no-interaction',
            ],
            path: dirname(__DIR__, 3),
            environment: [
                ...getenv(),
                'ENVIRONMENT' => 'testing',
                'VAULT_ROOT' => $this->archive->path('vault'),
                'STASHD_OPERATIONS_DSN' => $this->descriptor['dsn'],
                'STASHD_OPERATIONS_USERNAME' => $this->descriptor['username'],
                'STASHD_OPERATIONS_PASSWORD' => $this->descriptor['password'],
                'STASHD_OPERATIONS_SCHEMA' => 'configuration_proof',
            ],
        );
    }

    /**
     * Invoke a real command while independently sampling consumers; decode only a successful JSON receipt.
     */
    private function command(array $arguments): array
    {
        $result = $this->execute($this->pending($arguments));

        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Supervise one finite command through Tempest and always terminate/reap it on cancellation or failure.
     * Python's optional JSON lines describe completed physical generation/verification, not estimates.
     */
    private function execute(array|PendingProcess $command, ?string $activity = null): string
    {
        $process = new GenericProcessExecutor()->start($command instanceof PendingProcess ? $command : new PendingProcess(command: $command, path: dirname(__DIR__, 3)));
        $deadline = hrtime(true) + 600_000_000_000;
        $last = 0;
        try {
            do {
                $this->tick();
                if ($activity !== null) {
                    $lines = explode("\n", $process->output);
                    foreach (array_slice($lines, $last, max(0, count($lines) - $last - 1)) as $line) {
                        $last++;
                        if (str_starts_with($line, '{')) {
                            $this->update(json_decode($line, true, flags: JSON_THROW_ON_ERROR)['completed'], $activity);
                        }
                    }
                }

                if (hrtime(true) > $deadline) {
                    throw new RuntimeException('Experiment command exceeded its deadline.');
                }

                usleep(20_000);
            } while ($process->running);

            $result = $process->wait();
            if ($result->exitCode !== 0) {
                throw new RuntimeException('Experiment command failed (exit ' . $result->exitCode . ').');
            }

            if ($activity !== null) {
                $this->update($this->count, $activity);
            }

            return $result->output;
        } finally {
            $process->stop(0);
        }
    }

    /**
     * Atomically retain credential-free partial or final evidence outside the disposable workspace.
     */
    private function save(): void
    {
        $json = json_encode($this->report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        if (file_put_contents($this->path . '.tmp', $json) !== strlen($json) || ! rename($this->path . '.tmp', $this->path)) {
            throw new RuntimeException('Cannot retain filesystem experiment evidence.');
        }
    }
}

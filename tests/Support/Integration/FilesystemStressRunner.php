<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use Closure;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Throwable;

use function configuration_cli;
use function with_configuration_database;

/**
 * Own the disposable Filesystem experiment used by both Pest and the developer CLI.
 * Application commands still do all preservation; existing independent witnesses
 * must pass before this runner can return a successful report.
 */
final class FilesystemStressRunner
{
    /**
     * Execute one serial round trip and retain credential-free evidence, including failures.
     * The CLI restricts count to 100; the existing explicit stress suite retains larger cases.
     * SIGINT and SIGTERM unwind supervised workers and disposable fixtures, never a success.
     */
    public static function run(int $count, ?Closure $observer = null, ?string $reportDirectory = null): array
    {
        if (! in_array($count, [100, 1_000, 10_000], true)) {
            throw new InvalidArgumentException('Unsupported acceptance workload.');
        }

        $reportDirectory ??= getenv('STASHD_FILESYSTEM_STRESS_REPORT_DIR') ?: null;
        if ($reportDirectory === null && getenv('STASHD_PR5_REPORT_DIR')) {
            // Keep existing stress automation usable while callers migrate to the semantic name.
            $reportDirectory = getenv('STASHD_PR5_REPORT_DIR');
            fwrite(STDERR, 'STASHD_PR5_REPORT_DIR is deprecated; use STASHD_FILESYSTEM_STRESS_REPORT_DIR.' . "\n");
        }

        if ($reportDirectory !== null && (! is_dir($reportDirectory) || ! is_writable($reportDirectory))) {
            throw new InvalidArgumentException('Report directory must already exist and be writable.');
        }

        if (! function_exists('pcntl_async_signals')) {
            throw new RuntimeException('The stress runner requires Linux /proc and pcntl.');
        }

        require_once __DIR__ . '/configuration-cli.php';
        $name = 'filesystem-stress-' . $count . '-' . bin2hex(random_bytes(5));
        $report = ['format' => 'stashd-filesystem-stress-1', 'files' => $count, 'status' => 'incomplete', 'phases' => []];
        $metrics = null;
        $cleaned = false;
        $adapter = getenv('STASHD_TRANSACTION_TEST_ADAPTER');
        putenv('STASHD_TRANSACTION_TEST_ADAPTER=' . dirname(__DIR__) . '/RecordsAndArchives/transaction-adapter.php');
        $async = pcntl_async_signals(true);
        $interrupted = false;
        $handlers = [SIGINT => pcntl_signal_get_handler(SIGINT), SIGTERM => pcntl_signal_get_handler(SIGTERM)];
        foreach (array_keys($handlers) as $signal) {
            pcntl_signal($signal, static function () use (&$interrupted): void {
                pcntl_signal(SIGINT, SIG_IGN);
                pcntl_signal(SIGTERM, SIG_IGN);
                $interrupted = true;
            });
        }

        try {
            self::save($reportDirectory, $name, $report);
            $observe = static function ($progress) use ($observer, &$interrupted): void {
                if ($interrupted) {
                    throw new RuntimeException('Stress run interrupted.');
                }

                $observer?->__invoke($progress);
            };
            with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use (
                $count,
                $observe,
                $reportDirectory,
                $name,
                &$metrics,
                &$report,
            ): void {
                $metrics = new FilesystemStressObserver($archive, $database, $count, $observe, $reportDirectory, $name);
                $archive->onProcessTick = $metrics->refresh(...);
                try {
                    self::roundTrip($archive, $descriptor, $database, $count, $metrics);
                    $report = $metrics->finish($archive->path('vault'), pendingCleanup: true);
                } catch (Throwable $error) {
                    fwrite(
                        STDERR,
                        'Stress phase failed: '
                        . str_replace(
                            [$descriptor['dsn'], $descriptor['username'], $descriptor['password']],
                            '[redacted]',
                            $error->getMessage(),
                        )
                        . "\n",
                    );
                    $metrics->checkpoint('failed');
                    $report = $metrics->finish($archive->path('vault'), $error::class, pendingCleanup: true);
                    throw $error;
                } finally {
                    $archive->onProcessTick = null;
                    $metrics->close();
                }
            }, static function () use (&$cleaned): void {
                $cleaned = true;
            });
            if ($interrupted) {
                throw new RuntimeException('Stress run interrupted.');
            }

            $report['status'] = 'passed';
            $report['cleanup'] = 'completed';
        } catch (Throwable $error) {
            $report['status'] = 'failed';
            $report['failure_type'] = $error::class;
            $report['cleanup'] = $cleaned ? 'completed' : 'not_confirmed';
            $report['interrupted'] = $interrupted;
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            pcntl_async_signals($async);
            putenv($adapter === false ? 'STASHD_TRANSACTION_TEST_ADAPTER' : 'STASHD_TRANSACTION_TEST_ADAPTER=' . $adapter);
        }

        $report['report_file'] = $name . '.json';
        if ($metrics !== null) {
            $metrics->publish($report);
        } else {
            self::save($reportDirectory, $name, $report);
        }

        return $report;
    }

    /**
     * Save a small setup/failure report even when fixture provisioning cannot reach the observer.
     */
    private static function save(?string $directory, string $name, array $report): void
    {
        if ($directory === null) {
            return;
        }

        $path = $directory . '/' . $name . '.json';
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        Assert::assertSame(strlen($json), file_put_contents($path . '.tmp', $json));
        Assert::assertTrue(rename($path . '.tmp', $path));
    }

    /**
     * Run the independent acceptance checks in their original order, including the unchanged negative control.
     * No progress observation or completed Job may substitute for these assertions.
     */
    private static function roundTrip(ArchiveFixture $archive, array $descriptor, PDO $database, int $count, FilesystemStressObserver $metrics): void
    {
        $metrics->phase('generate_and_verify_source');
        $corpus = Pr2Directory::corpus($archive, $count);
        $metrics->checkpoint('fixture_generated', ['expected_files' => $count]);
        Assert::assertSame(0, FilesystemStressObserver::verify($corpus['manifest'], $corpus['source'], $count));
        $metrics->checkpoint('source_fixture_verified');

        $metrics->phase('discovery');
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], $count, fn () => $metrics->checkpoint('discovery_worker_settled'));
        $metrics->checkpoint('discovery_complete', ['discovered' => $count]);
        $empty = configuration_cli($archive, $descriptor, ['operations:work', '--once', '--limit=100']);
        Assert::assertSame(0, $empty['code'], $empty['output'] . $empty['errors']);
        $metrics->checkpoint('empty_worker_baseline');

        $metrics->phase('first_acquisition');
        self::acquire($archive, $descriptor, $scope['run'], $count, $metrics);
        $metrics->checkpoint('first_acquisition_settled');
        $metrics->phase('verify_preservation');
        Pr2Directory::assertPerFileAssignments($database, $scope['run'], $count);
        $members = Pr2Directory::members($archive, $descriptor, $scope['stash']);
        Assert::assertCount($count, $members);
        $preserved = Pr2Directory::assertPreserved($archive, $members, $corpus['entries']);
        $metrics->checkpoint('first_preservation_verified', ['items' => count($members), 'assets' => count($preserved)]);

        $metrics->phase('unchanged_rescan');
        $repeat = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        Pr2Directory::settle($archive, $descriptor, $repeat['assignment_id'], 3_600);
        $metrics->checkpoint('unchanged_discovery_complete');
        $secondDiscovery = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $repeat['run_id']]);
        Assert::assertSame('exhaustive', $secondDiscovery['coverage']['kind']);
        Assert::assertSame($count, $secondDiscovery['discovered_count']);
        $metrics->phase('unchanged_acquisition');
        self::acquire($archive, $descriptor, $repeat['run_id'], $count, $metrics);
        $metrics->checkpoint('unchanged_acquisition_settled');
        Pr2Directory::assertPerFileAssignments($database, $repeat['run_id'], $count);
        Assert::assertSame($members, Pr2Directory::members($archive, $descriptor, $scope['stash']));
        Assert::assertSame($preserved, Pr2Directory::assertPreserved($archive, $members, $corpus['entries']), 'An unchanged rescan must not create new Item/Asset revisions.');
        $metrics->checkpoint('unchanged_rescan_verified', ['items' => $count, 'assets' => $count]);

        $metrics->phase('remove_source');
        $archive->destroy('inputs');
        Assert::assertFalse(is_dir($corpus['source']), 'Input A must be unavailable before publication starts.');
        $metrics->checkpoint('source_removed');

        $metrics->phase('first_broadcast');
        $first = self::broadcast($archive, $descriptor, $scope['stash'], $count, $metrics);
        $metrics->checkpoint('first_broadcast_settled', ['reported_files' => $count]);
        Assert::assertSame(0, FilesystemStressObserver::verify($corpus['manifest'], $archive->path('output'), $count));
        $inodes = Pr2Directory::inodes($archive->path('output'), $corpus['entries']);
        $metrics->checkpoint('source_independent_broadcast_verified', ['reported_files' => $count]);

        $metrics->phase('repeat_broadcast');
        self::broadcast($archive, $descriptor, $scope['stash'], $count, $metrics, $first['broadcast']);
        $metrics->checkpoint('repeat_broadcast_settled', ['reported_files' => $count]);
        Assert::assertSame(0, FilesystemStressObserver::verify($corpus['manifest'], $archive->path('output'), $count));
        Assert::assertSame($inodes, Pr2Directory::inodes($archive->path('output'), $corpus['entries']));
        $metrics->checkpoint('repeat_broadcast_verified', ['reported_files' => $count]);

        $metrics->phase('corruption_negative_control');
        $firstPath = array_key_first($corpus['entries']);
        Assert::assertNotNull($firstPath);
        file_put_contents($archive->path('output/' . $firstPath), "deliberately altered output\n");
        Assert::assertNotSame(
            0,
            FilesystemStressObserver::verify($corpus['manifest'], $archive->path('output'), $count),
            'Corruption must defeat the independent external verifier.',
        );
        $metrics->checkpoint('negative_control_rejected');
    }

    /**
     * Refill at most 100 active per-file assignments and serially drain real durable workers.
     * The deadline prevents infinite loops; observed queue widths are samples, not continuous peaks.
     */
    private static function acquire(ArchiveFixture $archive, array $descriptor, string $run, int $count, FilesystemStressObserver $metrics): void
    {
        $metrics->observeRun($run);
        $deadline = microtime(true) + 14_400;
        $state = [];
        do {
            $started = hrtime(true);
            $admitted = Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $run]);
            Assert::assertNotEmpty($admitted['assignment_id'] ?? null);
            $admitSeconds = (hrtime(true) - $started) / 1_000_000_000;
            $started = hrtime(true);
            $state = Pr2Directory::admission($archive, $descriptor, $run, $count);
            $metrics->admissionCall($run, $admitSeconds, (hrtime(true) - $started) / 1_000_000_000);
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
            $metrics->workerBatch($run, $elapsed, (hrtime(true) - $started) / 1_000_000_000, $state['settled'] - $before);
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
     * Publish a real frozen selection, then verify all bounded report pages for unique positions.
     * Callers must independently check output bytes and OCFL evidence after this Job settles.
     *
     * @return array{broadcast: string, run: string}
     */
    private static function broadcast(ArchiveFixture $archive, array $descriptor, string $stash, int $count, FilesystemStressObserver $metrics, ?string $broadcast = null): array
    {
        if ($broadcast === null) {
            $configured = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
            $broadcast = $configured['id'];
        }

        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $stash, $broadcast]);
        $metrics->checkpoint('broadcast_selection_frozen');
        $receipt = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']]);
        Assert::assertNotEmpty($receipt['assignment_id'] ?? null);
        Pr2Directory::settle($archive, $descriptor, $receipt['assignment_id'], 3_600);
        $metrics->checkpoint('broadcast_worker_settled');
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
}

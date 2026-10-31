<?php

declare(strict_types=1);

namespace Tests\Support\Ecotone;

use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\InvokedProcess;
use Tests\Support\Operations\LocalOperationsDriver;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\TransactionProbe;

/**
 * Own one disposable installation and bounded native consumer processes.
 * Assertions query independent SQL, never a reconstructed delivery/completion ledger.
 */
final class NativeDeliveryFixture
{
    public readonly PDO $database;
    public readonly string $scope;
    public readonly string $root;
    private ArchiveFixture $workspace;
    private LocalOperationsDriver $allocator;
    private array $descriptor;
    private array $children = [];
    private array $reaped = [];
    private ?InvokedProcess $acknowledging = null;
    private ?int $ackBackend = null;

    /**
     * Allocate through the existing restricted-role fixture and explicit provisioning.
     * Set initialize to false to leave a genuinely empty database for installation tests.
     */
    public function __construct(bool $initialize = true)
    {
        $this->workspace = new ArchiveFixture();
        $this->root = $this->workspace->path('operations');
        mkdir($this->root, 0o700);
        $this->allocator = new LocalOperationsDriver();
        try {
            if ($initialize) {
                $this->scope = $this->allocator->run('probe.prepare', ['workspace' => $this->root, 'name' => 'native-durability'])['scope_id'];
            } else {
                $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
                $fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
                $path = $this->root . '/installation.json';
                file_put_contents($path, json_encode(['fixture' => $fixture], JSON_THROW_ON_ERROR));
                chmod($path, 0o600);
                $descriptor = TransactionProbe::run($this->root, ['operation' => 'projection.describe', 'fixture' => $fixture]);
                file_put_contents($path, json_encode(compact('fixture', 'descriptor'), JSON_THROW_ON_ERROR));
                $this->scope = Uuid::v7()->toString();
            }

            $this->descriptor = json_decode(file_get_contents($this->root . '/installation.json'), true, flags: JSON_THROW_ON_ERROR)['descriptor'];
            $this->database = LocalOperationsDriver::connect($this->descriptor);
        } catch (\Throwable $error) {
            $this->dispose();
            throw $error;
        }
    }

    /**
     * Launch normal Tempest bootstrap with generated credentials on stdin.
     *
     * @param array<string, mixed> $arguments
     */
    public function start(string $action, array $arguments = []): InvokedProcess
    {
        $pause = $this->root . '/pause-' . count($this->children);
        if (($arguments['boundary'] ?? '') === 'completed') {
            $this->database->exec('SELECT pg_advisory_lock(82, 2)');
            $this->database->exec("CREATE FUNCTION pause_ack() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM pg_advisory_xact_lock(82, 2); RETURN OLD; END'");
            $this->database->exec('CREATE TRIGGER pause_ack BEFORE DELETE ON enqueue FOR EACH ROW EXECUTE FUNCTION pause_ack()');
        }

        $child = new GenericProcessExecutor()->start(new \Tempest\Process\PendingProcess(
            command: [
                PHP_BINARY,
                __DIR__
                    . (
                        in_array($action, ['install', 'nested', 'official-nested', 'injected-nested', 'injected-lifecycle', 'injected-consume'], true)
                            ? '/installation-process.php'
                            : '/delivery-process.php'
                    ),
            ],
            input: json_encode([
                'descriptor' => $this->descriptor,
                'workspace' => $this->root,
                'pause' => $pause,
                'scope' => $this->scope,
                'action' => $action,
                ...$arguments,
            ], JSON_THROW_ON_ERROR),
            path: dirname(__DIR__, 3),
            timeout: Duration::seconds(18),
            environment: ['ENVIRONMENT' => $arguments['environment'] ?? 'testing', 'PGAPPNAME' => 'native-' . basename($this->root) . '-' . count($this->children)],
        ));
        $this->children[] = [$child, $pause];
        if (($arguments['boundary'] ?? '') === 'completed') {
            $this->acknowledging = $child;
        }

        return $child;
    }

    /**
     * Require successful process exit and decode its actual public outcome.
     */
    public function finish(InvokedProcess $child): array
    {
        $result = $child->wait();
        Assert::assertSame(0, $result->exitCode, $result->errorOutput . $result->output);

        return json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Run a single fresh application action to completion.
     *
     * @param array<string, mixed> $arguments
     */
    public function run(string $action, array $arguments = []): array
    {
        return $this->finish($this->start($action, $arguments));
    }

    /**
     * Require a live executor boundary, or independently observe the native queue DELETE barrier.
     */
    public function paused(InvokedProcess $child): void
    {
        if ($this->acknowledging === $child) {
            $deadline = microtime(true) + 8;
            do {
                $this->database->exec('SELECT pg_stat_clear_snapshot()');
                $backend = $this->database
                    ->query(
                        "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event = 'advisory' AND query ILIKE '%DELETE%enqueue%'",
                    )
                    ->fetchColumn();
                if ($backend !== false) {
                    $this->ackBackend = (int) $backend;
                    Assert::assertTrue($child->running);

                    return;
                }

                Assert::assertTrue($child->running, 'Consumer exited before the native acknowledgement barrier: ' . $child->errorOutput);
                usleep(10_000);
            } while (microtime(true) < $deadline);

            Assert::fail('Consumer did not reach the native queue DELETE barrier.');
        }

        $path = null;
        foreach ($this->children as [$candidate, $pause]) {
            if ($candidate !== $child) {
                continue;
            }

            $path = $pause;
        }

        $deadline = microtime(true) + 8;
        while (! is_file($path) && microtime(true) < $deadline) {
            Assert::assertTrue($child->running, 'Child exited before boundary: ' . $child->errorOutput);
            usleep(10_000);
        }

        Assert::assertFileExists($path, 'Native consumer did not reach its required boundary.');
        $receipt = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertSame($child->pid, $receipt['pid']);
        Assert::assertTrue($child->running);
    }

    /**
     * Send SIGKILL, reap, and require a non-graceful exit of the exact child.
     */
    public function kill(InvokedProcess $child): void
    {
        Assert::assertTrue($child->running);
        $child->signal(9);
        try {
            Assert::assertSame(137, $child->wait()->exitCode, 'SIGKILL must produce exit 137.');
        } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $error) {
            Assert::assertSame(9, $error->getSignal());
        }

        $this->reaped[spl_object_id($child)] = true;
        Assert::assertFalse($child->running);
        if ($this->acknowledging === $child) {
            // Client death cannot cancel a server-side DELETE already waiting for its lock.
            $this->database->query('SELECT pg_terminate_backend(' . $this->ackBackend . ')');
            $this->database->exec('SELECT pg_advisory_unlock(82, 2)');
            $this->database->exec('DROP TRIGGER pause_ack ON enqueue');
        }
    }

    /**
     * Advance real native visibility/claim deadlines, never fabricate a new Job.
     */
    public function expire(): void
    {
        $this->database->exec('UPDATE enqueue SET redeliver_after = 0, delayed_until = 0');
        $this->database->exec("UPDATE jobs SET lease_until = clock_timestamp() - interval '1 second' WHERE status = 'running'");
    }

    /**
     * Read persisted rows with a separate connection from every application process.
     */
    public function rows(string $sql): array
    {
        return $this->database->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Observe a database count without inferring it from process receipts.
     */
    public function count(string $table): int
    {
        if (! in_array($table, ['jobs', 'job_attempts', 'enqueue', 'probe_effects', 'ecotone_error_messages', 'bootstrap_probe'], true)) {
            throw new RuntimeException('Unknown observation table.');
        }

        return (int) $this->database->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }

    /**
     * Stop and reap every child before dropping only the generated database/role.
     */
    public function dispose(): void
    {
        foreach ($this->children as [$child]) {
            if (isset($this->reaped[spl_object_id($child)])) {
                continue;
            }

            if ($child->running) {
                $child->stop(0, 9);
            }

            $child->wait();
        }

        try {
            $this->allocator->run('fixture.dispose', ['workspace' => $this->root]);
            if (isset($this->descriptor)) {
                $config = json_decode(file_get_contents(dirname(__DIR__, 3) . '/tmp/transaction-projection-admin.json'), true, flags: JSON_THROW_ON_ERROR);
                if ($config['dsn'] !== 'pgsql:host=lerd-postgres;port=5432;dbname=postgres') {
                    throw new RuntimeException('Cleanup audit requires the explicit local test administrator.');
                }

                $admin = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $check = $admin->prepare('SELECT (SELECT count(*) FROM pg_database WHERE datname = ?) + (SELECT count(*) FROM pg_roles WHERE rolname = ?)');
                $check->execute([$this->descriptor['username'], $this->descriptor['username']]);
                Assert::assertSame(0, (int) $check->fetchColumn(), 'Disposable database and role must both be removed.');
            }
        } finally {
            $this->workspace->dispose();
            Assert::assertDirectoryDoesNotExist($this->root);
        }
    }
}

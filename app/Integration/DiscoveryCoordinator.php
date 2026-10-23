<?php

namespace App\Integration;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Coordinate manual discovery with durable source associations and atomic checkpoints.
 *
 * Runs fix configuration and complete-enumeration intent across invocations. SQL
 * is operational state only: discovery never mints Item URIs or touches Vault bytes.
 * One PostgreSQL session lock excludes cooperating invocations for the same Input.
 *
 * @phpstan-import-type Batch from InputDiscovery
 */
final readonly class DiscoveryCoordinator
{
    private const int BATCH_SIZE = 100;

    /**
     * Bind the operational store and the only currently registered discovery adapter.
     */
    public function __construct(
        private OperationsDatabase $database,
        private OperationsConfig $config,
        private IntegrationConfigurations $configurations,
        private InputDiscovery $input,
        private DiscoveryBatchValidator $batches,
    ) {}

    /**
     * Start or resume this Input's unfinished manual scan and return durable status.
     *
     * Retry reuses the current run and its acknowledged continuation. Failure keeps
     * committed batches; terminal acknowledgment wins over a subsequent exception.
     * No acquisition fan-out occurs. The session lock is released on process death.
     */
    public function discover(string $inputId): array
    {
        $configuration = $this->configurations->read('input', $inputId);
        $inputId = $configuration['id'];
        $adapter = match ($configuration['component']) {
            'filesystem' => $this->input,
            default => throw new InvalidArgumentException('Input has no registered in-process discovery adapter.'),
        };
        $database = $this->database->connect();
        $lock = [$this->config->schema, $inputId];
        if (! $this->query($database, 'SELECT pg_try_advisory_lock(hashtext(?), hashtext(?))', $lock)->fetchColumn()) {
            throw new RuntimeException('Discovery is already running for this Input.');
        }
        try {
            /** @var array{id: string, continuation: ?string, matches: bool}|false $row */
            $row = $this->query(
                $database,
                'SELECT *, configuration = CAST(? AS jsonb) AS matches FROM ' . $this->table('discovery_runs') . ' WHERE input_id = ? AND status = \'running\'',
                [
                    json_encode($configuration, JSON_THROW_ON_ERROR),
                    $inputId,
                ],
            )->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $id = Uuid::v7()->toString();
                $this->query($database, 'INSERT INTO ' . $this->table('discovery_runs') . ' (id, input_id, configuration, status) VALUES (?, ?, ?, \'running\')', [
                    $id,
                    $inputId,
                    json_encode($configuration, JSON_THROW_ON_ERROR),
                ]);
                $continuation = null;
            } else {
                $id = $row['id'];
                $continuation = $row['continuation'];
                if ($row['matches'] !== true) {
                    throw new RuntimeException('Cannot resume discovery under changed Input configuration.');
                }
            }
            $active = true;
            try {
                $adapter->discover([
                    'input_id' => $inputId,
                    'intent' => 'complete',
                    'options' => $configuration['settings'],
                    'continuation' => $continuation,
                    'refresh_state' => null,
                    'maximum_items_per_batch' => self::BATCH_SIZE,
                ], function (array $batch) use ($database, $id, &$continuation, &$active): void {
                    if (! $active) {
                        throw new RuntimeException('Discovery acknowledgment is outside its invocation.');
                    }
                    $this->commit($database, $id, $continuation, $batch);
                    $continuation = $batch['progress']['more'] ?? null;
                });
                $result = $this->readUsing($database, $id);
                if ($result['status'] !== 'finished') {
                    throw new RuntimeException('Discovery returned without an acknowledged terminal batch.');
                }
                return $result;
            } catch (\Throwable $error) {
                $result = $this->readUsing($database, $id);
                if ($result['status'] === 'finished') {
                    return $result;
                }
                $this->query($database, 'UPDATE ' . $this->table('discovery_runs') . ' SET error = ? WHERE id = ? AND status = \'running\'', [$error->getMessage(), $id]);
                throw new RuntimeException('Discovery run ' . $id . ' remains resumable: ' . $error->getMessage(), previous: $error);
            } finally {
                $active = false;
            }
        } finally {
            $this->query($database, 'SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', $lock);
        }
    }

    /**
     * Read compact durable status without contacting the source filesystem.
     */
    public function read(string $runId): array
    {
        return $this->readUsing($this->database->connect(), $runId);
    }

    /**
     * Return at most one bounded page of this run's persisted discovered Items.
     *
     * The cursor is an opaque source ID, not a path or a canonical Item identity.
     */
    public function items(string $runId, string $after = ''): array
    {
        $database = $this->database->connect();
        $this->readUsing($database, $runId);
        /** @var list<array{source_id: string, item: string}> $rows */
        $rows = $this->query(
            $database,
            'SELECT source_id, item FROM ' . $this->table('discovery_items') . ' WHERE run_id = ? AND source_id > ? ORDER BY source_id LIMIT 100',
            [$runId, $after],
        )->fetchAll(PDO::FETCH_ASSOC);
        return [
            'items' => array_map(fn (array $row): array => $this->record($row['item']), $rows),
            'next' => count($rows) === self::BATCH_SIZE ? $rows[array_key_last($rows)]['source_id'] : null,
        ];
    }

    /**
     * Atomically accept a validated batch's Items, associations and progress transition.
     *
     * Stale, outside-run, over-limit and post-terminal commits are rejected. SQL
     * failure rolls back all parts. Repeated source IDs update an observation,
     * never mint another association or delete a source missing from this scan.
     *
     * @param Batch $batch
     */
    private function commit(PDO $database, string $id, ?string $expected, array $batch): void
    {
        $this->batches->validate($batch, self::BATCH_SIZE);
        $database->beginTransaction();
        try {
            /** @var array{input_id: string, status: string, continuation: ?string}|false $row */
            $row = $this->query($database, 'SELECT input_id, status, continuation FROM ' . $this->table('discovery_runs') . ' WHERE id = ? FOR UPDATE', [$id])->fetch(
                PDO::FETCH_ASSOC,
            );
            if ($row === false || $row['status'] !== 'running' || $row['continuation'] !== $expected) {
                throw new RuntimeException('Discovery batch no longer belongs to the active checkpoint.');
            }
            foreach ($batch['items'] as $item) {
                $this->query(
                    $database,
                    'INSERT INTO ' . $this->table('source_associations') . ' (input_id, source_id, first_run, last_run) VALUES (?, ?, ?, ?)
                    ON CONFLICT (input_id, source_id) DO UPDATE SET last_run = EXCLUDED.last_run',
                    [$row['input_id'], $item['id'], $id, $id],
                );
                $this->query(
                    $database,
                    'INSERT INTO ' . $this->table('discovery_items') . ' (run_id, source_id, item) VALUES (?, ?, ?)
                    ON CONFLICT (run_id, source_id) DO UPDATE SET item = EXCLUDED.item',
                    [$id, $item['id'], json_encode($item, JSON_THROW_ON_ERROR)],
                );
            }
            $progress = $batch['progress'];
            $finished = isset($progress['finished']);
            $this->query(
                $database,
                'UPDATE ' . $this->table('discovery_runs') . ' SET status = ?, continuation = ?, coverage = ?, batches = batches + 1, error = NULL WHERE id = ?',
                [
                    $finished ? 'finished' : 'running',
                    $progress['more'] ?? null,
                    $finished ? json_encode($progress['finished'], JSON_THROW_ON_ERROR) : null,
                    $id,
                ],
            );
            $database->commit();
        } catch (\Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    /**
     * Read authoritative operational run status and its distinct observed Item count.
     */
    private function readUsing(PDO $database, string $id): array
    {
        if (! Uuid::isValid($id)) {
            throw new InvalidArgumentException('A valid discovery run UUID is required.');
        }
        /** @var array<string, mixed>|false $row */
        $row = $this->query(
            $database,
            'SELECT r.id, r.input_id, r.status, r.continuation, r.coverage, r.batches, r.error,
            (SELECT count(*) FROM ' . $this->table('discovery_items') . ' i WHERE i.run_id = r.id) AS discovered_count
            FROM ' . $this->table('discovery_runs') . ' r WHERE r.id = ?',
            [$id],
        )->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('Discovery run not found.');
        }
        $row['coverage'] = $row['coverage'] === null ? null : $this->record($row['coverage']);
        $row['batches'] = (int) $row['batches'];
        $row['discovered_count'] = (int) $row['discovered_count'];
        return $row;
    }

    /**
     * Execute bound operational SQL and propagate database errors to its transaction owner.
     */
    private function query(PDO $database, string $sql, array $values = []): PDOStatement
    {
        $statement = $database->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare discovery SQL.');
        }
        $statement->execute($values);
        return $statement;
    }

    /**
     * Decode a persisted JSON record while rejecting values that cannot be records.
     */
    private function record(string $json): array
    {
        $record = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($record)) {
            throw new RuntimeException('Malformed persisted discovery record.');
        }
        return $record;
    }

    /**
     * Qualify internal discovery tables with the already validated private schema.
     */
    private function table(string $name): string
    {
        return '"' . $this->config->schema . '".' . $name;
    }
}

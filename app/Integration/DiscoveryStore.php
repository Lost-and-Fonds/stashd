<?php

namespace App\Integration;

use App\Integration\DTO\DiscoveryBatch;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\Query;

use function Tempest\Database\query;

/**
 * Save scan runs, found files, and the source locations seen across runs.
 *
 * Files and scan progress are saved together so a restart cannot lose a batch.
 * This class owns the PostgreSQL locks and queries; these records describe source
 * locations and do not establish canonical Item identities.
 */
final readonly class DiscoveryStore
{
    /**
     * Use the application database and schema name to keep scan locks separate.
     */
    public function __construct(
        private OperationsDatabase $database,
        private OperationsConfig $config,
    ) {}

    /**
     * Prevent another caller from scanning the same Input until this connection releases its lock.
     *
     * PostgreSQL also releases the lock if the process dies.
     */
    public function lock(string $inputId): void
    {
        $row = $this->database->connect()->fetchFirst(new Query('SELECT pg_try_advisory_lock(hashtext(?), hashtext(?)) AS acquired', [$this->config->schema, $inputId]));
        if (($row['acquired'] ?? false) !== true) {
            throw new RuntimeException('Discovery is already running for this Input.');
        }
    }

    /**
     * Release the lock held by this connection for the Input.
     */
    public function unlock(string $inputId): void
    {
        $this->database->connect()->execute(new Query('SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', [$this->config->schema, $inputId]));
    }

    /**
     * Resume an unfinished run if its settings still match, or create a new run.
     *
     * Changed settings are rejected rather than mixed into an unfinished scan.
     * The caller must hold the Input lock until this scan attempt ends.
     *
     * @param array{id: string, kind: string, component: string, settings: array<string, mixed>} $configuration
     * @return array{id: string, continuation: ?string}
     */
    public function start(array $configuration): array
    {
        $database = $this->database->connect();
        /** @var array{id: string, continuation: ?string, matches: bool}|null $row */
        $row = $database->fetchFirst(new Query(
            "SELECT id, continuation, configuration = CAST(? AS jsonb) AS matches FROM discovery_runs WHERE input_id = ? AND status = 'running'",
            [json_encode($configuration, JSON_THROW_ON_ERROR), $configuration['id']],
        ));
        if ($row !== null) {
            if ($row['matches'] !== true) {
                throw new RuntimeException('Cannot resume discovery under changed Input configuration.');
            }

            return ['id' => $row['id'], 'continuation' => $row['continuation']];
        }

        $id = Uuid::v7()->toString();
        $database->execute(query('discovery_runs')->insert(
            id: $id,
            input_id: $configuration['id'],
            configuration: json_encode($configuration, JSON_THROW_ON_ERROR),
            status: 'running',
        ));

        return ['id' => $id, 'continuation' => null];
    }

    /**
     * Save the files we found and our scan position in the same database transaction.
     *
     * After a crash, we can resume without losing or duplicating a batch. Reject a
     * batch if the run has finished or its saved position has changed. Any SQL error
     * rolls back the whole batch. A repeated source ID updates the existing record.
     */
    public function commit(string $id, ?string $expected, DiscoveryBatch $batch): void
    {
        $database = $this->database->connect();
        $this->database->transaction(function () use ($database, $id, $expected, $batch): void {
            $row = $database->fetchFirst(new Query('SELECT input_id, status, continuation FROM discovery_runs WHERE id = ? FOR UPDATE', [$id]));
            if ($row === null || $row['status'] !== 'running' || $row['continuation'] !== $expected) {
                throw new RuntimeException('Discovery batch no longer belongs to the active checkpoint.');
            }

            foreach ($batch->items as $item) {
                $database->execute(new Query(
                    'INSERT INTO source_associations (input_id, source_id, first_run, last_run) VALUES (?, ?, ?, ?)
                    ON CONFLICT (input_id, source_id) DO UPDATE SET last_run = EXCLUDED.last_run',
                    [$row['input_id'], $item->id, $id, $id],
                ));
                $database->execute(new Query(
                    'INSERT INTO discovery_items (run_id, source_id, item) VALUES (?, ?, ?)
                    ON CONFLICT (run_id, source_id) DO UPDATE SET item = EXCLUDED.item',
                    [$id, $item->id, json_encode($item, JSON_THROW_ON_ERROR)],
                ));
            }

            $finished = isset($batch->progress['finished']);
            $database->execute(new Query(
                'UPDATE discovery_runs SET status = ?, continuation = ?, coverage = ?, batches = batches + 1, error = NULL WHERE id = ?',
                [$finished ? 'finished' : 'running', $batch->progress['more'] ?? null, $finished ? json_encode($batch->progress['finished'], JSON_THROW_ON_ERROR) : null, $id],
            ));
        });
    }

    /**
     * Save the error for retry without changing the last successfully saved scan position.
     */
    public function fail(string $id, string $message): void
    {
        $this->database->connect()->execute(query('discovery_runs')->update(error: $message)->where('id', $id)->where('status', 'running'));
    }

    /**
     * Read run status and its file count in one query so they describe the same saved state.
     */
    public function read(string $id): array
    {
        if (! Uuid::isValid($id)) {
            throw new InvalidArgumentException('A valid discovery run UUID is required.');
        }

        $row = $this->database
            ->connect()
            ->fetchFirst(new Query(
                'SELECT r.id, r.input_id, r.status, r.continuation, r.coverage, r.batches, r.error,
            (SELECT count(*) FROM discovery_items i WHERE i.run_id = r.id) AS discovered_count
            FROM discovery_runs r WHERE r.id = ?',
                [$id],
            ));
        if ($row === null) {
            throw new InvalidArgumentException('Discovery run not found.');
        }

        if ($row['coverage'] !== null) {
            $coverage = json_decode($row['coverage'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($coverage)) {
                throw new RuntimeException('Malformed persisted discovery record.');
            }

            $row['coverage'] = $coverage;
        }

        $row['batches'] = (int) $row['batches'];
        $row['discovered_count'] = (int) $row['discovered_count'];

        return $row;
    }

    /**
     * Read one page of saved files without opening the source directory.
     */
    public function items(string $id, string $after, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('A positive discovery page limit is required.');
        }

        $this->read($id);
        /** @var list<array{source_id: string, item: string}> $rows */
        $rows = $this->database
            ->connect()
            ->fetch(
                query('discovery_items')
                    ->select('source_id', 'item')
                    ->where('run_id', $id)
                    ->whereRaw('source_id > ?', $after)
                    ->orderBy('source_id')
                    ->limit($limit),
            );

        return [
            'items' => array_map(static function (array $row): array {
                $item = json_decode($row['item'], true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($item)) {
                    throw new RuntimeException('Malformed persisted discovery record.');
                }

                return $item;
            }, $rows),
            'next' => count($rows) === $limit ? $rows[$limit - 1]['source_id'] : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Integration;

use Closure;
use InvalidArgumentException;
use Tempest\Database\Query;

use function Tempest\Database\query;

/**
 * Save working publication state; only complete runs expose their report records.
 *
 * @phpstan-type FrozenAsset array{id: string, version: string, path: string, size: int}
 * @phpstan-type FrozenItem array{id: string, version: string, assets: list<FrozenAsset>, metadata: list<array{schema: string, json: string}>}
 */
final readonly class BroadcastStore
{
    /**
     * Retain the existing lazy operational database connection.
     */
    public function __construct(
        private OperationsDatabase $database,
    ) {}

    /**
     * Save every selected exact revision together, or leave no run on failure.
     *
     * @param iterable<FrozenItem> $records
     */
    public function freeze(string $id, array $configuration, iterable $records): void
    {
        $this->database->transaction(function () use ($id, $configuration, $records): void {
            $database = $this->database->connect();
            $database->execute(query('broadcast_runs')->insert(
                id: $id,
                broadcast_id: $configuration['id'],
                settings: json_encode($configuration['settings'], JSON_THROW_ON_ERROR),
                status: 'ready',
            ));
            $position = 0;
            foreach ($records as $record) {
                $database->execute(query('broadcast_selection')->insert(
                    run_id: $id,
                    position: $position++,
                    item_id: $record['id'],
                    version: $record['version'],
                    record: json_encode($record, JSON_THROW_ON_ERROR),
                ));
            }
        });
    }

    /**
     * Return operational status without treating it as archival evidence.
     */
    public function read(string $id): array
    {
        return $this->database->connect()->fetchFirst(query('broadcast_runs')->select()->where('id', $id)) ?? throw new InvalidArgumentException('Broadcast run not found.');
    }

    /**
     * Read a bounded forward page of the frozen selection.
     *
     * @return list<array{position: int|string, record: string}>
     */
    public function selection(string $id, int $after, int $limit): array
    {
        $rows = $this->database
            ->connect()
            ->fetch(query('broadcast_selection')->select('position', 'record')->where('run_id', $id)->whereRaw('position > ?', $after)->orderBy('position')->limit($limit));
        $validated = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['position'] ?? null) || ! is_string($row['record'] ?? null)) {
                throw new InvalidArgumentException('Stored publication selection is malformed.');
            }

            $validated[] = ['position' => $row['position'], 'record' => $row['record']];
        }

        return $validated;
    }

    /**
     * Serialize publishers for one configured destination until process exit or return.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function locked(string $broadcast, Closure $work): mixed
    {
        $database = $this->database->connect();
        $database->execute(new Query('SELECT pg_advisory_lock(hashtext(current_schema()), hashtext(?))', ['broadcast:' . $broadcast]));
        try {
            return $work();
        } finally {
            $database->execute(new Query('SELECT pg_advisory_unlock(hashtext(current_schema()), hashtext(?))', ['broadcast:' . $broadcast]));
        }
    }

    /**
     * Discard prior provisional reports before retrying the same frozen selection.
     */
    public function begin(string $id, string $attempt): void
    {
        $this->database->transaction(function () use ($id, $attempt): void {
            $database = $this->database->connect();
            $database->execute(query('broadcast_reports')->delete()->where('run_id', $id));
            $database->execute(query('broadcast_runs')->update(status: 'running', attempt: $attempt, files: null, error: null)->where('id', $id));
        });
    }

    /**
     * Save an entire provisional batch, rolling back duplicates and other failures.
     *
     * @param array<array> $records
     */
    public function report(string $id, string $attempt, int $position, string $kind, array $records): void
    {
        $this->database->transaction(function () use ($id, $attempt, $position, $kind, $records): void {
            foreach ($records as $record) {
                $this->database
                    ->connect()
                    ->execute(query('broadcast_reports')->insert(
                        run_id: $id,
                        attempt: $attempt,
                        position: $position++,
                        kind: $kind,
                        path: $kind === 'file' ? $record['relativePath'] : null,
                        record: json_encode($record, JSON_THROW_ON_ERROR),
                    ));
            }
        });
    }

    /**
     * Expose the current attempt's reports only after lifecycle validation succeeds.
     */
    public function complete(string $id, string $files): void
    {
        $this->database->connect()->execute(query('broadcast_runs')->update(status: 'complete', files: $files, error: null)->where('id', $id));
    }

    /**
     * Discard provisional output and retain an observable retryable run failure.
     */
    public function fail(string $id, string $error): void
    {
        $this->database->transaction(function () use ($id, $error): void {
            $database = $this->database->connect();
            $database->execute(query('broadcast_reports')->delete()->where('run_id', $id));
            $database->execute(query('broadcast_runs')->update(status: 'failed', files: null, error: $error)->where('id', $id));
        });
    }

    /**
     * Return at most 100 committed reports; running and failed attempts expose none.
     */
    public function results(string $id, int $after = -1): array
    {
        $run = $this->read($id);
        if ($run['status'] !== 'complete') {
            return [];
        }

        return $this->database
            ->connect()
            ->fetch(
                query('broadcast_reports')
                    ->select('position', 'kind', 'record')
                    ->where('run_id', $id)
                    ->where('attempt', $run['attempt'])
                    ->whereRaw('position > ?', $after)
                    ->orderBy('position')
                    ->limit(100),
            );
    }
}

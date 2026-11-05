<?php

declare(strict_types=1);

namespace App\Integration;

use App\Identity\Identifier;
use App\Operations\Assignment;
use App\Operations\AssignmentState;
use App\Operations\Worker;
use RuntimeException;
use Tempest\Database\Query;

use function Tempest\Database\query;

/**
 * Admit every eligible saved discovery, using bounded pages rather than an outstanding-Job ceiling.
 */
final readonly class AcquisitionAdmission
{
    /**
     * Reuse the application transaction owner, discovery locks and ordinary Worker admission.
     */
    public function __construct(
        private OperationsDatabase $database,
        private DiscoveryStore $discovery,
        private AcquisitionStore $attempts,
        private AcquisitionCoordinator $acquisition,
        private Worker $worker,
    ) {}

    /**
     * Admit the finished run under its admission lock; retry only failed Assignments.
     * Admission plus the attempt pointer commit together, before the separate inclusive cursor write.
     * first_assignment_id names the first admitted source's current Assignment, not newly admitted work.
     * assignment_id remains its compatibility alias for PR 1 clients.
     */
    public function fill(string $runId): array
    {
        $run = $this->discovery->read($runId);
        if ($run['status'] !== 'finished') {
            throw new RuntimeException('Finish discovery before admitting acquisitions.');
        }

        $this->database->connect()->execute(new Query('SELECT pg_advisory_lock(hashtext(current_schema()), hashtext(?))', ['acquisition-admission:' . $runId]));
        try {
            $db = $this->database->connect();
            $prepared = $db->fetchFirst(new Query('SELECT count(*) AS count FROM acquisition_attempts WHERE run_id = ?', [$runId]));
            assert($prepared !== null, 'A count query always returns a row.');
            if ((int) $prepared['count'] !== $run['discovered_count']) {
                // Explicit synchronous scans can also enter this user-initiated admission boundary.
                $this->acquisition->catalogue($runId, null);
            }

            $after = '';
            do {
                $retry = $db->fetch(
                    new Query(
                        'SELECT t.source_id, t.assignment_id FROM acquisition_attempts t JOIN operation_assignments a ON a.uuid = t.assignment_id WHERE t.run_id = ? AND t.source_id > ? AND (a.active OR a.error IS NOT NULL) ORDER BY t.source_id LIMIT 100',
                        [$runId, $after],
                    ),
                );
                foreach ($retry as $row) {
                    assert(is_array($row), 'The database returns saved Assignment pointers.');
                    $after = $row['source_id'];
                    $status = $this->worker->status(Identifier::fromUuid(Assignment::class, $row['assignment_id']));
                    if ($status->state === AssignmentState::Failed) {
                        $this->admit($runId, $row['source_id']);
                    }
                }
            } while (count($retry) === 100);

            $cursor = $db->fetchFirst(query('discovery_runs')->select('admission_cursor')->where('id', $runId));
            $after = $cursor['admission_cursor'] ?? '';
            // Include already admitted sources beyond the cursor: a crash may have committed their pointers.
            do {
                $page = $this->attempts->items($runId, $after);
                foreach ($page as $attempt) {
                    assert(is_array($attempt), 'The store returns saved per-source attempts.');
                    if ($attempt['assignment_id'] === null) {
                        $this->admit($runId, $attempt['source_id']);
                    }

                    $db->execute(query('discovery_runs')->update(admission_cursor: $attempt['source_id'])->where('id', $runId));
                    $after = $attempt['source_id'];
                }
            } while (count($page) === 100);

            $first = $db->fetchFirst(
                query('acquisition_attempts')->select('assignment_id')->where('run_id', $runId)->where('assignment_id IS NOT NULL')->orderBy('source_id')->limit(1),
            );

            $firstId = $first === null ? null : (string) Identifier::fromUuid(Assignment::class, $first['assignment_id']);

            return ['first_assignment_id' => $firstId, 'assignment_id' => $firstId, ...$this->read($runId)];
        } finally {
            $this->database->connect()->execute(new Query('SELECT pg_advisory_unlock(hashtext(current_schema()), hashtext(?))', ['acquisition-admission:' . $runId]));
        }
    }

    /**
     * Persist the Worker outbox and this source's Assignment pointer in the same shared transaction.
     * A failed Demand insert cannot leave either admission progress or a dangling pointer.
     */
    private function admit(string $run, string $source): void
    {
        $this->database->transaction(function () use ($run, $source): void {
            $assignment = $this->worker->do(new AcquireFile($run, $source));
            $this->database
                ->connect()
                ->execute(query('acquisition_attempts')->update(assignment_id: $assignment->id->uuidFor(Assignment::class))->where('run_id', $run)->where('source_id', $source));
        });
    }

    /**
     * Read persisted admission and actual Assignment completion with one bounded attempt page.
     * Admission totals share a SQL snapshot. Bounded failure-status pages may observe later worker
     * transitions, including unreconciled terminal Durable failures.
     * These observations never establish preservation or current Vault health.
     * @return array{run_id: string, total: int, admitted: int, settled: int, outstanding: int, failed: int, cursor: ?string, status: string, items: array, next: ?string}
     */
    public function read(string $runId, string $after = ''): array
    {
        $this->discovery->read($runId);
        $row = $this->database->connect()->fetchFirst(new Query(<<<'SQL'
        SELECT r.admission_cursor, r.status AS discovery_status,
            (SELECT count(*) FROM discovery_items d WHERE d.run_id = r.id) AS total,
            count(a.uuid) AS admitted,
            count(a.uuid) FILTER (WHERE NOT a.active AND a.error IS NULL) AS settled,
            count(a.uuid) FILTER (WHERE a.active OR a.error IS NOT NULL) AS outstanding
        FROM discovery_runs r LEFT JOIN acquisition_attempts t ON t.run_id = r.id
        LEFT JOIN operation_assignments a ON a.uuid = t.assignment_id
        WHERE r.id = ? GROUP BY r.id
        SQL, [$runId]));
        if ($row === null) {
            throw new RuntimeException('Discovery run disappeared during admission inspection.');
        }

        $failed = 0;
        $cursor = '';
        do {
            $unresolved = $this->database
                ->connect()
                ->fetch(new Query(
                    'SELECT t.source_id, a.uuid FROM acquisition_attempts t JOIN operation_assignments a ON a.uuid = t.assignment_id WHERE t.run_id = ? AND t.source_id > ? AND (a.active OR a.error IS NOT NULL) ORDER BY t.source_id LIMIT 100',
                    [$runId, $cursor],
                ));
            foreach ($unresolved as $assignment) {
                assert(is_array($assignment), 'The database returns saved Assignment pointers.');
                $cursor = $assignment['source_id'];
                if ($this->worker->status(Identifier::fromUuid(Assignment::class, $assignment['uuid']))->state === AssignmentState::Failed) {
                    $failed++;
                }
            }
        } while (count($unresolved) === 100);

        $items = $this->attempts->items($runId, $after);
        $last = end($items);
        $next = count($items) === 100 && is_array($last) ? $last['source_id'] : null;
        assert($next === null || is_string($next), 'Saved source cursors are strings.');
        assert($row['admission_cursor'] === null || is_string($row['admission_cursor']), 'The run stores a nullable source cursor.');

        return [
            'run_id' => $runId,
            'total' => (int) $row['total'],
            'admitted' => (int) $row['admitted'],
            'settled' => (int) $row['settled'],
            'outstanding' => (int) $row['outstanding'],
            'failed' => $failed,
            'cursor' => $row['admission_cursor'],
            'status' => $failed > 0 ? 'failed' : ($row['discovery_status'] === 'finished' && (int) $row['settled'] === (int) $row['total'] ? 'complete' : 'running'),
            'items' => $items,
            'next' => $next,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use App\Operations\Authority\AuthorityLost;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\CustodyHandoff;
use App\Operations\DTO\AssignmentContext;
use App\Operations\Durable\DurableConnections;
use App\Operations\HandlesJob;
use App\Operations\Models\Checkpoint;
use App\Operations\Models\Result;
use Doctrine\DBAL\Connection;

/**
 * Record independent observable effects through a constructor-injected service.
 */
#[HandlesJob(DepartmentJob::class, 'department.probe.v1')]
final readonly class DepartmentHandler
{
    /**
     * Use the fixture's real database, without replacing admission or execution.
     */
    public function __construct(
        private DurableConnections $connections,
        private PublicationAuthority $authority,
        private DepartmentControl $control,
        private CustodyHandoff $custody,
    ) {}

    /**
     * Keep effects independent of Assignment state and deliberately allow duplicates.
     */
    public function __invoke(DepartmentJob $request, AssignmentContext $execution): void
    {
        if ($request->custodyPath !== null) {
            Checkpoint::record($this->authority, $execution, 'staged');
            $this->custody->acknowledge($execution, 'retained', $request->custodyPath);
        }

        if (! $this->control->insideMutation) {
            $this->barrier($execution);
        }

        if ($this->control->fail) {
            throw new \RuntimeException('identifiable handler failure');
        }

        if ($request->custodyPath !== null) {
            try {
                Result::adopt($this->authority, $execution, 'verified');
                throw new \LogicException('Adoption succeeded without reconciliation.');
            } catch (\RuntimeException $expected) {
                if ($expected->getMessage() !== 'Retained custody requires reconciliation.') {
                    throw $expected;
                }
            }

            $this->custody->reconcile($execution);
            Result::adopt($this->authority, $execution, 'verified');
            Result::adopt($this->authority, $execution, 'verified');
            try {
                Result::adopt($this->authority, $execution, 'conflicting');
                throw new \LogicException('A conflicting result was adopted.');
            } catch (\RuntimeException $expected) {
                if ($expected->getMessage() !== 'A different result was already adopted.') {
                    throw $expected;
                }
            }
        }

        $this->authority->protect($execution, function (Connection $database) use ($request, $execution): void {
            $database->insert('effects', ['assignment' => (string) $execution->assignmentId, 'value' => $request->scope . ':' . $request->mode . ':' . $request->observation]);
            if ($this->control->insideMutation) {
                $this->barrier($execution);
            }
        });
    }

    /**
     * Pause a real handler or protected mutation while an independent process revokes ownership.
     */
    private function barrier(AssignmentContext $execution): void
    {
        if ($this->control->barrier === null) {
            return;
        }

        file_put_contents($this->control->barrier . '.writing', json_encode([
            'attempt' => $execution->attemptId,
            'demand' => $execution->demandId,
            'pid' => $this->connections->claims->fetchOne('SELECT pg_backend_pid()'),
        ], JSON_THROW_ON_ERROR));
        rename($this->control->barrier . '.writing', $this->control->barrier);
        $deadline = microtime(true) + 12;
        while (! is_file($this->control->barrier . '.release')) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Handler barrier timed out.');
            }

            if (is_file($this->control->barrier . '.revoke') && ! is_file($this->control->barrier . '.revoked')) {
                $this->connections->claims->executeQuery('SELECT pg_advisory_unlock_all()');
                $checks = [];
                foreach ([
                    'checkpoint' => fn () => Checkpoint::record($this->authority, $execution, 'stale'),
                    'custody' => fn () => $this->custody->acknowledge($execution, 'stale', $this->control->barrier),
                    'reconciliation' => fn () => $this->custody->reconcile($execution),
                    'result' => fn () => Result::adopt($this->authority, $execution, 'stale'),
                ] as $name => $operation) {
                    try {
                        $operation();
                        $checks[$name] = 'UNFENCED';
                    } catch (AuthorityLost) {
                        $checks[$name] = 'rejected';
                    }
                }

                file_put_contents($this->control->barrier . '.revoked', json_encode($checks, JSON_THROW_ON_ERROR));
            }

            usleep(10_000);
        }
    }
}

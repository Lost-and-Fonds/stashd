<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use App\Operations\DTO\AssignmentContext;
use App\Operations\Durable\DurableConnections;
use App\Operations\HandlesJob;

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
        private \App\Operations\Authority\PublicationAuthority $authority,
        private DepartmentControl $control,
        private \App\Operations\ExecutionArtifacts $artifacts,
    ) {}

    /**
     * Keep effects independent of Assignment state and deliberately allow duplicates.
     */
    public function __invoke(DepartmentJob $request, AssignmentContext $execution): void
    {
        if ($request->custodyPath !== null) {
            $this->artifacts->checkpoint($execution, 'staged');
            $this->artifacts->custody($execution, 'retained', $request->custodyPath);
        }

        if (! $this->control->insideMutation) {
            $this->barrier($execution);
        }

        if ($this->control->fail) {
            throw new \RuntimeException('identifiable handler failure');
        }

        if ($request->custodyPath !== null) {
            try {
                $this->artifacts->result($execution, 'verified');
                throw new \LogicException('Adoption succeeded without reconciliation.');
            } catch (\RuntimeException $expected) {
                if ($expected->getMessage() !== 'Retained custody requires reconciliation.') {
                    throw $expected;
                }
            }

            $this->artifacts->reconcile($execution);
            $this->artifacts->result($execution, 'verified');
            $this->artifacts->result($execution, 'verified');
            try {
                $this->artifacts->result($execution, 'conflicting');
                throw new \LogicException('A conflicting result was adopted.');
            } catch (\RuntimeException $expected) {
                if ($expected->getMessage() !== 'A different result was already adopted.') {
                    throw $expected;
                }
            }
        }

        $this->authority->protect($execution, function (\Doctrine\DBAL\Connection $database) use ($request, $execution): void {
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

        file_put_contents($this->control->barrier, json_encode([
            'attempt' => $execution->attemptId,
            'demand' => $execution->demandId,
            'pid' => $this->connections->claims->fetchOne('SELECT pg_backend_pid()'),
        ], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 12;
        while (! is_file($this->control->barrier . '.release')) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Handler barrier timed out.');
            }

            usleep(10_000);
        }
    }
}

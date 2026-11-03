<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Let Durable journal each observation as a separate replayable activity.
 */
#[AsWorkflow('operations.assignment.v1')]
final class AssignmentWorkflow
{
    /**
     * Reconcile retained demand without repeating completed workflow activities.
     */
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $workflow, string $assignment, string $demand): void
    {
        $activity = $workflow->activityStub(AssignmentActivity::class);
        do {
            $demand = $workflow->await($activity->__call('execute', ['assignment' => $assignment, 'demand' => $demand]));
        } while ($demand !== null);
    }
}

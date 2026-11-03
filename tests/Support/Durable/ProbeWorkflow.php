<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Replay a deterministic plan with a single externally executed activity.
 */
#[AsWorkflow('spike')]
final class ProbeWorkflow
{
    /**
     * Suspend until the persisted activity result becomes available.
     */
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $workflow, string $value): string
    {
        return $workflow->await($workflow->activityStub(ProbeActivity::class)->effect($value));
    }
}

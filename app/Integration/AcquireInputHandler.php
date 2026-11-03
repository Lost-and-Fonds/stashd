<?php

declare(strict_types=1);

namespace App\Integration;

use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\HandlesJob;
use App\Operations\Models\Result;
use RuntimeException;

/**
 * Reuse Integration work and accept its result through the Operations fence.
 */
#[HandlesJob(AcquireInput::class, 'integration.acquireinput.v1')]
final readonly class AcquireInputHandler
{
    /**
     * Reuse acquisition recovery and the Operations result fence.
     */
    public function __construct(
        private AcquisitionCoordinator $acquisition,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Execute existing Integration work and record verified coverage under the current Assignment authority.
     */
    public function __invoke(AcquireInput $job, AssignmentContext $context): void
    {
        $result = $this->acquisition->acquire($job->runId);
        if (array_diff(array_keys($result['counts']), ['preserved', 'unchanged']) !== []) {
            throw new RuntimeException('Acquisition has unpreserved files.');
        }

        Result::record($this->authority, $context, json_encode($result, JSON_THROW_ON_ERROR));
    }
}

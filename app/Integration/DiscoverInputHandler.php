<?php

declare(strict_types=1);

namespace App\Integration;

use App\Catalogue\Catalogue;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\HandlesJob;
use App\Operations\Models\Result;

/**
 * Reuse Integration work and accept its result through the Operations fence.
 */
#[HandlesJob(DiscoverInput::class, 'integration.discoverinput.v1')]
final readonly class DiscoverInputHandler
{
    /**
     * Connect discovery, existing identity recovery and collection membership to Operations.
     */
    public function __construct(
        private DiscoveryCoordinator $discovery,
        private AcquisitionCoordinator $acquisition,
        private Catalogue $catalogue,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Execute existing Integration work and record verified coverage under the current Assignment authority.
     */
    public function __invoke(DiscoverInput $job, AssignmentContext $context): void
    {
        $run = $this->discovery->read($job->runId);
        $result = $this->discovery->discover($run['input_id'], $job->runId);
        $this->acquisition->catalogue($job->runId, $context);
        $this->catalogue->discovered($job->runId, $context);
        Result::record($this->authority, $context, json_encode($result, JSON_THROW_ON_ERROR));
    }
}

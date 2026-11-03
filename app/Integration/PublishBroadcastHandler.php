<?php

declare(strict_types=1);

namespace App\Integration;

use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\HandlesJob;
use App\Operations\Models\Result;

/**
 * Reuse Integration work and accept its result through the Operations fence.
 */
#[HandlesJob(PublishBroadcast::class, 'integration.publishbroadcast.v1')]
final readonly class PublishBroadcastHandler
{
    /**
     * Reuse the Vault-only publisher and the Operations result fence.
     */
    public function __construct(
        private BroadcastCoordinator $broadcast,
        private FilesystemPublisher $filesystem,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Execute existing Integration work and record verified coverage under the current Assignment authority.
     */
    public function __invoke(PublishBroadcast $job, AssignmentContext $context): void
    {
        $result = $this->broadcast->execute($job->runId, $this->filesystem);
        Result::record($this->authority, $context, json_encode($result, JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace App\Integration;

use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\HandlesJob;
use App\Operations\Models\Result;
use RuntimeException;

/**
 * Execute one file through existing recoverable preservation and fenced result acceptance.
 */
#[HandlesJob(AcquireFile::class, 'integration.acquire-file.v1')]
final readonly class AcquireFileHandler
{
    /**
     * Reuse acquisition custody and the Operations authority without creating another runtime.
     */
    public function __construct(
        private AcquisitionCoordinator $acquisition,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Accept coverage only after the exact file is preserved or verified unchanged.
     */
    public function __invoke(AcquireFile $job, AssignmentContext $context): void
    {
        $result = $this->acquisition->acquireFile($job->runId, $job->sourceId, $context);
        if (! in_array($result['status'], ['preserved', 'unchanged'], true)) {
            throw new RuntimeException('Acquisition has not preserved the requested file.');
        }

        Result::record($this->authority, $context, json_encode($result, JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace App\RecordsAndArchives;

use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\HandlesJob;
use App\Operations\Models\Result;
use League\Uri\Urn;

/**
 * Verify requested archival bytes without publishing or repairing any Vault content.
 */
#[HandlesJob(VerifyAsset::class, 'preservation.verify-asset.v1')]
final readonly class VerifyAssetHandler
{
    /**
     * Reuse archival verification and Operations' fenced result recording.
     */
    public function __construct(
        private AssetArchive $assets,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Record exact verified file evidence only after inspection succeeds.
     *
     * Verification errors propagate to Operations and Durable's existing retries.
     * Coverage reports this check's evidence, not continuing health or other versions.
     */
    public function __invoke(VerifyAsset $job, AssignmentContext $context): void
    {
        $record = $this->assets->inspect(Urn::new($job->objectId), $job->version);
        Result::record($this->authority, $context, json_encode([
            'object_id' => $record['object_id'],
            'version' => $record['version'],
            'files' => $record['files'],
        ], JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Providers\StashdUri;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashId;
use App\Vault\MediaItemId;
use Tempest\DateTime\DateTime;

final readonly class DownloadRequest
{
    public function __construct(
        public MediaItemId $mediaItemId,
        public StashId $stashId,
        public string $providerKey,
        public string $providerItemId,
        public StashdUri $canonicalUri,
        public DownloadPolicy $downloadPolicy,
        public string $tempDirectory,
        public bool $force = false,
        public ?int $durationSeconds = null,
        public ?StashdUri $thumbnailUri = null,
        public ?string $title = null,
        public ?DateTime $publishedAt = null,
        /** @var array<string, bool|string> */
        public array $providerOptions = [],
    ) {}
}

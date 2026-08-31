<?php

declare(strict_types=1);

namespace App\Providers;

final readonly class ResolvedInput
{
    public function __construct(
        public string $providerKey,
        public string $inputType,
        public StashdUri $sourceUri,
        public string $providerInputId,
        public ?string $title = null,
        public ?string $sourceTitle = null,
        public ?StashdUri $sourceAvatarUri = null,
        public ?int $estimatedItemCount = null,
        public ?int $sizeBytes = null,
        public bool $sizeEstimated = false,
    ) {}
}

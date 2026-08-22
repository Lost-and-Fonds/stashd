<?php

declare(strict_types=1);

namespace App\Stashes;

use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'stash_inputs')]
final class StashInputRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'stashId')]
    public StashRecord $stash;

    public function __construct(
        public StashId $stashId,
        public string $providerKey,
        public StashInputType $inputType,
        public string $sourceUri,
        public string $providerInputId,
        public StashInputState $state,
        public int $consecutiveFailures = 0,
        public ?string $title = null,
        public ?SyncMode $syncMode = null,
        public ?StashInputOptions $options = null,
        public ?DateTime $lastCheckedAt = null,
        public ?DateTime $nextCheckAt = null,
        public ?DateTime $lastSuccessAt = null,
        public ?DateTime $lastFailureAt = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}

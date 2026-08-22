<?php

declare(strict_types=1);

namespace App\Stashes;

use Tempest\Database\HasMany;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'stashes')]
final class StashRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /** @var StashItemRecord[] */
    #[HasMany(ownerJoin: 'stashId')]
    public array $items;

    public function __construct(
        public string $name,
        public SyncMode $syncMode,
        public DownloadPolicy $downloadPolicy,
        public OrganizationMode $organizationMode,
        public StashState $state,
        public ?string $description = null,
        public ?string $iconUri = null,
        public ?string $videoQualityProfileId = null,
        public ?string $audioQualityProfileId = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}

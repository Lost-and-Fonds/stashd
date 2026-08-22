<?php

declare(strict_types=1);

namespace App\Commands;

use App\Auth\UserId;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'commands')]
final class CommandRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /**
     * @param  array<string, mixed>|null  $options
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        public CommandType $type,
        public CommandState $state,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?array $options = null,
        public ?array $result = null,
        public ?UserId $createdByUserId = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}

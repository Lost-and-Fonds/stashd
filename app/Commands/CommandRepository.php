<?php

declare(strict_types=1);

namespace App\Commands;

use App\Auth\UserId;
use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class CommandRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param array<string, mixed>|null $options */
    public function create(
        CommandType $type,
        ?string $targetType = null,
        ?PrefixedUlid $targetId = null,
        ?array $options = null,
        ?UserId $createdByUserId = null,
    ): CommandRecord {
        $id = $this->ids->generate('cmd')->toString();
        $record = new CommandRecord(
            type: $type,
            state: CommandState::Accepted,
            targetType: $targetType,
            targetId: $targetId?->toString(),
            options: $options,
            createdByUserId: $createdByUserId,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(CommandRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(CommandId $id): ?CommandRecord
    {
        return CommandRecord::findById($id->toPrimaryKey());
    }

    public function save(CommandRecord $record): CommandRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    public function latestForTarget(CommandType $type, string $targetType, string $targetId): ?CommandRecord
    {
        $command = CommandRecord::select()
            ->where('type', $type)
            ->where('targetType', $targetType)
            ->where('targetId', $targetId)
            ->orderBy('createdAt', Direction::DESC)
            ->first();

        return $command instanceof CommandRecord ? $command : null;
    }

    /** @return list<CommandRecord> */
    public function listRecent(int $limit = 50): array
    {
        return CommandRecord::select()
            ->orderBy('createdAt', Direction::DESC)
            ->limit($limit)
            ->all();
    }
}

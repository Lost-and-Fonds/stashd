<?php

declare(strict_types=1);

namespace App\Connections;

use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class ConnectionRepository
{
    public function __construct(private PrefixedUlidGenerator $ids)
    {
    }

    /** @param array<string, mixed>|null $settings */
    public function create(
        string $pluginKey,
        string $name,
        string $endpoint,
        ConnectionState $state = ConnectionState::Ready,
        ?array $settings = null,
        ?string $credentialSecretId = null,
    ): ConnectionRecord {
        $record = new ConnectionRecord(
            type: $pluginKey,
            name: $name,
            baseUri: $endpoint,
            state: $state,
            tokenSecretId: $credentialSecretId,
            settings: $settings,
        );
        $record->id = new PrimaryKey($this->ids->generate('connect')->toString());
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt = $now;
        $record->updatedAt = $now;

        query(ConnectionRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(PrefixedUlid $id): ?ConnectionRecord
    {
        return ConnectionRecord::select()->include('tokenSecretId')->get($id->toPrimaryKey());
    }

    public function save(ConnectionRecord $record): ConnectionRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    /** @return list<ConnectionRecord> */
    public function listAll(): array
    {
        return array_values(ConnectionRecord::select()->orderBy('createdAt', \Tempest\Database\Direction::ASC)->all());
    }

    public function delete(ConnectionRecord $record): void
    {
        $record->delete();
    }
}

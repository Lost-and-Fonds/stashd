<?php

declare(strict_types=1);

namespace App\System\Secret;

use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class SecretRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function findByKey(string $key): ?SecretRecord
    {
        return SecretRecord::select()
            ->where('key', $key)
            ->whereNull('revokedAt')
            ->include('encryptedValue', 'nonce', 'tokenDigest', 'metadata')
            ->first();
    }

    public function find(PrefixedUlid $id): ?SecretRecord
    {
        return SecretRecord::findById($id->toPrimaryKey());
    }

    public function findActiveBroadcastTokenByDigest(string $digest): ?SecretRecord
    {
        $secret = SecretRecord::select()
            ->where('type', SecretType::BroadcastToken)
            ->where('tokenDigest', $digest)
            ->whereNull('revokedAt')
            ->first();

        return $secret instanceof SecretRecord ? $secret : null;
    }

    /** @return list<SecretRecord> */
    public function listActiveBroadcastTokensWithoutDigest(): array
    {
        $records = SecretRecord::select()
            ->where('type', SecretType::BroadcastToken)
            ->whereNull('tokenDigest')
            ->whereNull('revokedAt')
            ->all();
        $secrets = [];

        foreach ($records as $record) {
            if ($record instanceof SecretRecord) {
                $secrets[] = $record;
            }
        }

        return $secrets;
    }

    /** @param array<string, mixed>|null $metadata */
    public function create(
        string $key,
        SecretType $type,
        string $encryptedValue,
        string $nonce,
        ?string $tokenDigest = null,
        ?array $metadata = null,
    ): SecretRecord {
        $id = $this->ids->generate('secret')->toString();
        $record = new SecretRecord(
            key: $key,
            type: $type,
            encryptedValue: $encryptedValue,
            nonce: $nonce,
            tokenDigest: $tokenDigest,
            metadata: $metadata,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(SecretRecord::class)->insert($record)->execute();

        return $record;
    }

    public function save(SecretRecord $record): SecretRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class ApiTokenRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param list<string>|null $scopes */
    public function create(
        UserId $userId,
        string $name,
        string $tokenHash,
        string $tokenPreview,
        ?array $scopes = null,
        ?DateTime $expiresAt = null,
    ): ApiTokenRecord {
        $id = $this->ids->generate('token')->toString();
        $now = DateTime::now(Timezone::UTC);
        $record = new ApiTokenRecord(
            userId: $userId,
            name: $name,
            tokenHash: $tokenHash,
            tokenPreview: $tokenPreview,
            scopes: $scopes === null ? null : ApiTokenScopes::fromArray($scopes),
            expiresAt: $expiresAt,
            createdAt: $now,
        );
        $record->id = new PrimaryKey($id);

        query(ApiTokenRecord::class)->insert($record)->execute();

        return $record;
    }

    public function findByHash(string $tokenHash): ?ApiTokenRecord
    {
        /** @var ApiTokenRecord|null $record */
        $record = ApiTokenRecord::select()
            ->where('tokenHash', $tokenHash)
            ->whereNull('revokedAt')
            ->first();

        return $record;
    }

    /** @return list<ApiTokenRecord> */
    public function listForUser(UserId $userId): array
    {
        /** @var list<ApiTokenRecord> $records */
        $records = ApiTokenRecord::select()
            ->where('userId', $userId->toString())
            ->whereNull('revokedAt')
            ->orderBy('createdAt', Direction::DESC)
            ->all();

        return $records;
    }

    public function revoke(ApiTokenId $tokenId): void
    {
        $record = ApiTokenRecord::findById($tokenId->toPrimaryKey());

        if ($record === null || $record->revokedAt !== null) {
            return;
        }

        $record->revokedAt = DateTime::now(Timezone::UTC);
        $record->save();
    }
}

<?php

declare(strict_types=1);

namespace App\System\Secret;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\Mapper\Hidden;

#[Table(name: 'secrets')]
final class SecretRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public string $key,
        public SecretType $type,
        #[Hidden]
        public string $encryptedValue,
        #[Hidden]
        public string $nonce,
        #[Hidden]
        public ?string $tokenDigest = null,
        #[Hidden]
        public ?array $metadata = null,
        public ?DateTime $lastUsedAt = null,
        public ?DateTime $revokedAt = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}

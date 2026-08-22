<?php

declare(strict_types=1);

namespace App\Auth;

use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\Mapper\Hidden;

#[Table(name: 'api_tokens')]
final class ApiTokenRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'userId')]
    public UserRecord $user;

    public function __construct(
        public UserId $userId,
        public string $name,
        #[Hidden]
        public string $tokenHash,
        public ?string $tokenPreview = null,
        public ?ApiTokenScopes $scopes = null,
        public ?DateTime $lastUsedAt = null,
        public ?DateTime $expiresAt = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $revokedAt = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Connections;

use App\System\Secret\SecretRecord;
use App\System\Secret\SecretsService;
use Tempest\Database\PrimaryKey;

final readonly class ConnectionSecrets
{
    public function __construct(private SecretsService $secrets)
    {
    }

    public function resolve(ConnectionRecord $connection): ?string
    {
        if ($connection->tokenSecretId === null) {
            return null;
        }

        $secret = SecretRecord::findById(new PrimaryKey($connection->tokenSecretId));

        return $secret === null ? null : $this->secrets->get($secret->key);
    }
}

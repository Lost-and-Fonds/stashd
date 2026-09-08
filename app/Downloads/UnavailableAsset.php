<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Vault\AssetKind;
use App\Vault\AssetRole;

final readonly class UnavailableAsset
{
    public function __construct(
        public AssetRole $role,
        public AssetKind $kind,
        public bool $permanent,
        public string $message,
    ) {}
}

<?php

declare(strict_types=1);

use App\Administration\VaultConfig;

use function Tempest\env;
use function Tempest\root_path;

// The only place we read the configured Vault provider and root.
return new VaultConfig(
    provider: env('VAULT_PROVIDER', default: 'local'),
    root: env('VAULT_ROOT', default: root_path('storage/vault')),
);

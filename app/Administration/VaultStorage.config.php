<?php

declare(strict_types=1);

use App\Administration\VaultConfig;
use Tempest\Storage\Config\LocalStorageConfig;

use function Tempest\Container\get;

// VaultConfig.config.php is loaded first, then this file derives the
// provider-specific Tempest configuration from the same Vault settings.
$vault = get(VaultConfig::class);

if ($vault->provider !== 'local') {
    throw new LogicException("Unsupported Vault storage provider: {$vault->provider}");
}

// Tempest's StorageInitializer can resolve #[Tag('vault')] Storage from this.
// LocalArchiveStorage will wrap that Storage when its implementation is ready.
return new LocalStorageConfig(
    path: $vault->root,
    tag: 'vault',
);

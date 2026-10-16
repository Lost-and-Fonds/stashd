<?php

use App\Administration\VaultConfig;
use App\Administration\LocalStorageConfig;
use function Tempest\Container\get;

$vault = get(VaultConfig::class);

return new LocalStorageConfig(
    path: $vault->root,
    tag: 'vault',
);
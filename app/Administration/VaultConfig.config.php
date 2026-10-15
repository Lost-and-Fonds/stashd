<?php

namespace App\Administration;

use function Tempest\env;

final class VaultConfig
{
    public function __construct(
        public readonly string $provider,
        public readonly string $root
    ) {}
}
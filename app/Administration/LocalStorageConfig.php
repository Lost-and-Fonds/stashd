<?php

namespace App\Administration;

readonly class LocalStorageConfig
{
    public function __construct(
        public string $path,
        public string $tag
    ) {}
}
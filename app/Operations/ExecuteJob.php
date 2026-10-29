<?php

namespace App\Operations;

final readonly class ExecuteJob
{
    public function __construct(
        public string $id,
        public int $generation = 0,
    ) {}
}

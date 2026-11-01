<?php

namespace Tests\Support\HistoricalOperations;

final readonly class ExecuteJob
{
    public function __construct(
        public string $id,
        public int $generation = 0,
    ) {}
}

<?php

namespace App\Operations;

final readonly class Jobs
{
    public function __construct(
        private JobRuntime $runtime,
    ) {}

    public function admit(string $key, string $payload): string
    {
        return $this->runtime->admit($key, $payload);
    }
}

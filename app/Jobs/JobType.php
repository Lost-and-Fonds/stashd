<?php

declare(strict_types=1);

namespace App\Jobs;

use InvalidArgumentException;

final readonly class JobType implements \Stringable
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_.-]*$/', $value)) {
            throw new InvalidArgumentException("Invalid job type: {$value}");
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function core(string $name): self
    {
        return new self(str_starts_with($name, 'core.') ? $name : 'core.' . $name);
    }
}

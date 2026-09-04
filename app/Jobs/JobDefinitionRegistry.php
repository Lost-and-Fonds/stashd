<?php

declare(strict_types=1);

namespace App\Jobs;

use InvalidArgumentException;
use Tempest\Container\Singleton;

#[Singleton]
final class JobDefinitionRegistry
{
    /** @var array<string, JobDefinition> */
    private array $definitions = [];

    /** @param list<JobDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(JobDefinition $definition): void
    {
        $key = (string) $definition->type;

        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Job type already registered: {$key}");
        }

        $this->definitions[$key] = $definition;
    }

    public function get(string $type): JobDefinition
    {
        return $this->definitions[$type]
            ?? throw new InvalidArgumentException("Unknown job type: {$type}");
    }

}

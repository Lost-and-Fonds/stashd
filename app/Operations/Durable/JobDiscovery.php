<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\HandlesJob;
use App\Operations\Job;
use Tempest\Discovery\Discovery;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Discovery\IsDiscovery;
use Tempest\Reflection\ClassReflector;

final class JobDiscovery implements Discovery
{
    use IsDiscovery;

    public function __construct(
        private JobRegistry $registry,
    ) {}

    public function discover(DiscoveryLocation $location, ClassReflector $class): void
    {
        if ($attribute = $class->getAttribute(HandlesJob::class)) {
            $this->discoveryItems->add($location, [$attribute->job, $class->getName(), $attribute->type]);
        }
    }

    public function apply(): void
    {
        foreach ($this->discoveryItems as $item) {
            /** @var array{class-string<Job>, class-string, string} $item */
            [$job, $handler, $type] = $item;
            $this->registry->register($job, $handler, $type);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\HandlesJob;
use Tempest\Discovery\Discovery;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Discovery\IsDiscovery;
use Tempest\Reflection\ClassReflector;

/**
 * Register departmental handlers through Tempest's cached class discovery.
 */
final class JobDiscovery implements Discovery
{
    use IsDiscovery;

    /**
     * Keep discovery free of database and worker side effects.
     */
    public function __construct(
        private JobRegistry $registry,
    ) {}

    /**
     * Cache only class names and their declared Job class.
     */
    public function discover(DiscoveryLocation $location, ClassReflector $class): void
    {
        if ($attribute = $class->getAttribute(HandlesJob::class)) {
            $this->discoveryItems->add($location, [$attribute->job, $class->getName()]);
        }
    }

    /**
     * Install the same registrations for fresh and cached framework boots.
     */
    public function apply(): void
    {
        foreach ($this->discoveryItems as $item) {
            /** @var array{class-string<\App\Operations\Job>, class-string} $item */
            [$job, $handler] = $item;
            $this->registry->register($job, $handler);
        }
    }
}

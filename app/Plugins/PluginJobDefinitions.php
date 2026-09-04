<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Jobs\JobDefinition;
use App\Jobs\JobType;

final class PluginJobDefinitions
{
    /**
     * @param array<string, mixed> $manifest
     * @return list<JobDefinition>
     */
    public static function fromManifest(array $manifest): array
    {
        $jobs = [];

        foreach (is_array($manifest['jobs'] ?? null) ? $manifest['jobs'] : [] as $job) {
            if (! is_array($job) || ! is_string($job['type'] ?? null) || ! is_string($job['handler'] ?? null)) {
                continue;
            }

            /** @var class-string $handler */
            $handler = $job['handler'];

            $jobs[] = new JobDefinition(
                type: new JobType($job['type']),
                handler: $handler,
                workload: is_string($job['workload'] ?? null) ? $job['workload'] : 'background',
            );
        }

        return $jobs;
    }
}

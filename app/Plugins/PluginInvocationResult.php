<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginInvocationResult
{
    /**
     * @param list<array{fraction: float, stage: string}> $progress
     * @param list<string> $logs
     */
    public function __construct(
        public array $progress,
        public array $logs,
        public int $sourceBytes,
        public string $outputId,
        public int $outputBytes,
    ) {
    }
}

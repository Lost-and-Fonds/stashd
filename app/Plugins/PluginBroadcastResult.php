<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginBroadcastResult
{
    /**
     * @param  list<array{fraction: float, stage: string}>  $progress
     * @param  list<string>  $logs
     * @param  array<string, mixed>  $publication
     */
    public function __construct(
        public array $progress,
        public array $logs,
        public array $publication,
    ) {}
}

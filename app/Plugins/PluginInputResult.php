<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class PluginInputResult
{
    /**
     * @param list<array{fraction: float, stage: string}> $progress
     * @param list<string> $logs
     * @param array<string, mixed>|null $resolved
     * @param list<array<string, mixed>>|null $items
     */
    public function __construct(
        public array $progress,
        public array $logs,
        public ?array $resolved,
        public ?array $items,
    ) {
    }
}

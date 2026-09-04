<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Providers\InputOption;
use App\Providers\ResolvedInput;

final readonly class InputPreflightResult
{
    /**
     * @param list<array<string, mixed>> $discoveredItems
     * @param list<InputOption> $inputOptions
     */
    public function __construct(
        public string $sourceUri,
        public ?string $sourceTitle,
        public ResolvedInput $resolvedInput,
        public string $strategyKey,
        public int $estimatedItemCount,
        public int $estimatedTotalDurationSeconds,
        public array $discoveredItems,
        public array $inputOptions = [],
    ) {}

    /** @return list<array<string, mixed>> */
    public function sampleItems(int $limit = 5): array
    {
        return array_slice($this->discoveredItems, 0, $limit);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sizes = array_column($this->discoveredItems, 'size_bytes');
        $knownSizes = array_filter($sizes, static fn(mixed $size): bool => is_int($size) || is_float($size));
        $totalSize = $knownSizes !== [] ? array_sum($knownSizes) : null;
        $unknownSizeCount = count($sizes) - count($knownSizes);
        $sizeEstimated = $totalSize !== null && ($unknownSizeCount > 0 || array_filter($this->discoveredItems, static fn(array $item): bool => ($item['size_estimated'] ?? false) === true) !== []);

        return [
            'source_uri' => $this->sourceUri,
            'source_title' => $this->sourceTitle,
            'resolved_input' => [
                'provider_key' => $this->resolvedInput->providerKey,
                'input_type' => $this->resolvedInput->inputType,
                'source_uri' => $this->resolvedInput->sourceUri->toString(),
                'provider_input_id' => $this->resolvedInput->providerInputId,
                'title' => $this->resolvedInput->title,
                'source_title' => $this->resolvedInput->sourceTitle,
                'source_avatar_uri' => $this->resolvedInput->sourceAvatarUri?->toString(),
                'estimated_item_count' => $this->resolvedInput->estimatedItemCount,
                'size_bytes' => $this->resolvedInput->sizeBytes,
                'size_estimated' => $this->resolvedInput->sizeEstimated,
            ],
            'discovery' => [
                'strategy_key' => $this->strategyKey,
                'estimated_item_count' => $this->estimatedItemCount,
                'estimated_total_duration_seconds' => $this->estimatedTotalDurationSeconds,
                'estimated_total_size_bytes' => $totalSize,
                'estimated_total_size_estimated' => $sizeEstimated,
                'estimated_total_size_known_items' => count($knownSizes),
                'estimated_total_size_item_count' => count($sizes),
                'discovered_items' => $this->discoveredItems,
                'sample_items' => $this->sampleItems(),
            ],
            'universal_filters' => [
                ['key' => 'title_regex_include', 'label' => 'Only include titles matching (regex)', 'type' => 'string'],
                ['key' => 'title_regex_exclude', 'label' => 'Exclude titles matching (regex)', 'type' => 'string'],
            ],
            'input_options' => array_map(
                static fn(InputOption $option): array => $option->toArray(),
                $this->inputOptions,
            ),
        ];
    }
}

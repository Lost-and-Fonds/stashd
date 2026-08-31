<?php

declare(strict_types=1);

namespace App\Providers;

use InvalidArgumentException;

final readonly class ProviderStrategySelector
{
    public function select(
        Provider $provider,
        StrategyPurpose $purpose,
        ?StrategySelectionOptions $options = null,
    ): ProviderStrategy {
        $options ??= new StrategySelectionOptions();

        $strategies = match ($purpose) {
            StrategyPurpose::Discovery => $provider->discoveryStrategies(),
            StrategyPurpose::Metadata => $provider->metadataStrategies(),
            StrategyPurpose::Download => $provider->downloadStrategies(),
            StrategyPurpose::Availability => [],
        };

        if ($strategies === []) {
            throw new InvalidArgumentException("No {$purpose->value} strategies registered for provider {$provider->key()}.");
        }

        $strategies = array_values(array_filter(
            $strategies,
            static fn(ProviderStrategy $strategy): bool => $provider->isStrategyAvailable($strategy),
        ));

        if (! $options->allowLastResort) {
            $strategies = array_values(array_filter(
                $strategies,
                static fn(ProviderStrategy $strategy): bool => $strategy->cost !== StrategyCost::LastResort,
            ));
        }

        if ($strategies === []) {
            throw new InvalidArgumentException("No available {$purpose->value} strategies for provider {$provider->key()}.");
        }

        if ($options->preferIncremental) {
            $incremental = array_values(array_filter(
                $strategies,
                static fn(ProviderStrategy $strategy): bool => $strategy->supportsIncremental,
            ));

            if ($incremental !== []) {
                $strategies = $incremental;
            }
        }

        usort($strategies, static function (ProviderStrategy $a, ProviderStrategy $b) use ($options): int {
            $costOrder = [
                StrategyCost::Low->value => 0,
                StrategyCost::Medium->value => 1,
                StrategyCost::High->value => 2,
                StrategyCost::LastResort->value => 3,
            ];

            $costCompare = $costOrder[$a->cost->value] <=> $costOrder[$b->cost->value];

            if ($options->preferHighestCapability) {
                $costCompare = -$costCompare;
            }

            if ($costCompare !== 0) {
                return $costCompare;
            }

            return $a->priority <=> $b->priority;
        });

        return $strategies[0];
    }
}

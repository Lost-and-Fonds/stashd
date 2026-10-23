<?php

namespace App\Integration;

use RuntimeException;
use Tempest\Validation\Rules\HasCount;
use Tempest\Validation\Rules\IsArrayList;
use Tempest\Validation\Validator;
use Throwable;

use function Tempest\Mapper\map;

/**
 * Validate raw protocol values before mapping them into named discovery records.
 *
 * Tempest Strict mapping owns required-vs-null checks; declarative rules reject
 * coercible scalars before casters run. Only protocol variant invariants are manual.
 */
final readonly class DiscoveryBatchValidator
{
    /**
     * Bind the application's real validator rather than duplicating field validation.
     */
    public function __construct(
        private Validator $validator,
    ) {}

    /**
     * Return typed observations only after complete nested validation and boundedness.
     */
    public function validate(array $batch, int $maximumItems): DiscoveryBatch
    {
        $this->check(DiscoveryBatch::class, $batch);
        if ($this->validator->validateValue($batch['items'], new HasCount(max: $maximumItems)) !== []) {
            throw new RuntimeException('Discovery batch exceeds the host limit.');
        }
        /** @var list<array{metadata: array}> $items */
        $items = $batch['items'];
        foreach ($items as $item) {
            $this->check(DiscoveredItem::class, $item);
            $this->metadata($item['metadata']);
            $observation = $this->record(DiscoveredItem::class, $item);
            if ($observation->size_bytes === null && $observation->size_estimated) {
                throw new RuntimeException('A discovery size estimate requires a numeric size.');
            }
        }
        /** @var array $progress */
        $progress = $batch['progress'];
        if (array_keys($progress) === ['more']) {
            if ($this->validator->validateValue($progress['more'], new \Tempest\Validation\Rules\IsNotEmptyString()) !== []) {
                throw new RuntimeException('Discovery continuation must be a nonempty string.');
            }
        } elseif (array_keys($progress) === ['finished']) {
            $this->finish($progress['finished']);
        } else {
            throw new RuntimeException('Discovery progress must contain exactly one coherent variant.');
        }
        return $this->record(DiscoveryBatch::class, $batch);
    }

    /**
     * Check variant field membership separately from each named record's value rules.
     */
    private function finish(mixed $values): void
    {
        $result = $this->record(DiscoveryResult::class, $values);
        $detail = match ($result->kind) {
            'exhaustive' => 'refresh_state',
            'partial' => 'deficiencies',
            'indeterminate' => 'diagnostic',
            default => throw new RuntimeException('Unrecognized terminal coverage.'),
        };
        $keys = array_keys($values);
        sort($keys);
        $expected = ['kind', $detail];
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('Terminal coverage must contain exactly its variant fields.');
        }
        if ($result->kind === 'indeterminate') {
            $this->diagnostic($result->diagnostic);
        } elseif ($result->kind === 'partial') {
            if ($this->validator->validateValue($result->deficiencies, [new IsArrayList(), new HasCount(min: 1)]) !== []) {
                throw new RuntimeException('Partial coverage requires a nonempty deficiency list.');
            }
            foreach ($result->deficiencies ?? [] as $values) {
                $deficiency = $this->record(DiscoveryDeficiency::class, $values);
                $this->diagnostic($deficiency->diagnostic);
            }
        }
    }

    /**
     * Validate evidence envelopes before any nested mapper can coerce their scalars.
     */
    private function diagnostic(mixed $value): void
    {
        $this->check(DiscoveryDiagnostic::class, $value);
        /** @var array{evidence: array} $value */
        $this->metadata($value['evidence']);
        $this->record(DiscoveryDiagnostic::class, $value);
    }

    /**
     * Keep component JSON opaque while strictly validating each facet envelope.
     */
    private function metadata(array $values): void
    {
        foreach ($values as $value) {
            $this->record(DiscoveryMetadata::class, $value);
        }
    }

    /**
     * Apply installed class/property validation before Tempest's potentially coercing casters.
     *
     * @param class-string $class
     */
    private function check(string $class, mixed $value): void
    {
        if (! is_array($value) || $this->validator->validateValue($value, new IsDiscoveryRecord()) !== [] || $this->validator->validateValuesForClass($class, $value) !== []) {
            throw new RuntimeException('Rejected malformed discovery record: ' . $class);
        }
    }

    /**
     * Let Strict mapping enforce required nullable properties without a custom field walker.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function record(string $class, mixed $value): object
    {
        $this->check($class, $value);
        try {
            $record = map($value)->to($class);
            if (! $record instanceof $class) {
                throw new RuntimeException('Mapper did not produce the requested discovery record.');
            }
            return $record;
        } catch (Throwable $error) {
            throw new RuntimeException('Rejected malformed discovery record: ' . $class, previous: $error);
        }
    }
}

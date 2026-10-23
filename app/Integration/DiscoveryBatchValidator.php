<?php

namespace App\Integration;

use RuntimeException;
use Tempest\Validation\Rule;
use Tempest\Validation\Rules;
use Tempest\Validation\Validator;

/**
 * Validate discovery value records before the host starts a batch transaction.
 *
 * Tempest field rules describe envelopes; strict scalar closures preserve protocol
 * types without coercion. Component-owned metadata payloads remain opaque strings.
 *
 * @phpstan-import-type Batch from InputDiscovery
 * @phpstan-import-type Item from InputDiscovery
 * @phpstan-import-type ValidationRule from Validator
 * @phpstan-import-type ValidationClosure from Validator
 */
final readonly class DiscoveryBatchValidator
{
    /**
     * Bind the application's validator while keeping lifecycle state outside validation.
     */
    public function __construct(
        private Validator $validator,
    ) {}

    /**
     * Reject invalid records, oversized batches and inconsistent terminal variants.
     *
     * Required nullable fields must be present, not merely defaulted to null.
     * Transaction ownership, active-run checks and acknowledgment remain Core duties.
     */
    public function validate(array $batch, int $maximumItems): void
    {
        /** @var Batch $batch */
        $batch = $this->fields($batch, [
            'items' => [new Rules\IsArrayList(), new Rules\HasCount(max: $maximumItems)],
            'progress' => new Rules\IsArray(),
        ]);
        foreach ($batch['items'] as $item) {
            /** @var Item $item */
            $item = $this->fields($item, [
                'id' => new Rules\IsNotEmptyString(),
                'reference' => is_string(...),
                'source' => new Rules\IsArray(orNull: true),
                'size_bytes' => static fn (mixed $value): bool => $value === null || is_int($value) && $value >= 0,
                'size_estimated' => is_bool(...),
                'metadata' => new Rules\IsArrayList(),
            ]);
            if ($item['size_bytes'] === null && $item['size_estimated']) {
                throw new RuntimeException('A discovery size estimate requires a numeric size.');
            }
            $this->metadata($item['metadata']);
        }
        $progress = $batch['progress'];
        if (array_keys($progress) === ['more']) {
            $this->fields($progress, ['more' => new Rules\IsNotEmptyString()]);
            return;
        }
        $progress = $this->fields($progress, ['finished' => new Rules\IsArray()]);
        $finish = $this->fields($progress['finished'], ['kind' => new Rules\IsIn(['exhaustive', 'partial', 'indeterminate'])]);
        if (count($progress) !== 1 || count($finish) !== 2) {
            throw new RuntimeException('Discovery progress must contain exactly one coherent variant.');
        }
        if ($finish['kind'] === 'exhaustive') {
            $this->fields($finish, ['refresh_state' => static fn (mixed $value): bool => $value === null || is_string($value)]);
            return;
        }
        if ($finish['kind'] === 'indeterminate') {
            $this->fields($finish, ['diagnostic' => new Rules\IsArray()]);
            $this->diagnostic($finish['diagnostic']);
            return;
        }
        $this->fields($finish, ['deficiencies' => [new Rules\IsArrayList(), new Rules\HasCount(min: 1)]]);
        /** @var list<array> $deficiencies */
        $deficiencies = $finish['deficiencies'];
        foreach ($deficiencies as $deficiency) {
            $deficiency = $this->fields($deficiency, [
                'disposition' => new Rules\IsIn(['retryable', 'terminal', 'unknown']),
                'diagnostic' => new Rules\IsArray(),
            ]);
            $this->diagnostic($deficiency['diagnostic']);
        }
    }

    /**
     * Require a complete diagnostic envelope without interpreting provider evidence.
     */
    private function diagnostic(array $value): void
    {
        $this->fields($value, ['message' => is_string(...), 'evidence' => new Rules\IsArrayList()]);
        $this->metadata($value['evidence']);
    }

    /**
     * Validate facet envelopes while retaining their component-owned string payloads.
     */
    private function metadata(array $values): void
    {
        foreach ($values as $value) {
            $this->fields($value, ['schema' => is_string(...), 'json' => is_string(...)]);
        }
    }

    /**
     * Require every named field before applying Tempest's field-rule map.
     *
     * validateValues examines supplied keys only; checking presence separately
     * prevents missing nullable fields from passing as explicit null values.
     *
     * @param array<string, Rule|ValidationClosure|array<ValidationRule>> $rules
     */
    private function fields(mixed $value, array $rules): array
    {
        if (! is_array($value) || array_diff(array_keys($rules), array_keys($value)) !== []) {
            throw new RuntimeException('Rejected malformed discovery fields: ' . implode(', ', array_keys($rules)));
        }
        $fields = [];
        foreach ($rules as $name => $rule) {
            $fields[$name] = $value[$name];
        }
        if ($this->validator->validateValues($fields, $rules) !== []) {
            throw new RuntimeException('Rejected malformed discovery fields: ' . implode(', ', array_keys($rules)));
        }
        return $value;
    }
}

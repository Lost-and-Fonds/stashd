<?php

use App\Integration\DiscoveryBatchValidator;
use Tempest\Container\GenericContainer;

/**
 * Supply a canonical Item observation for focused protocol value rejection tests.
 */
function validation_discovered_item(): array
{
    return ['id' => 'stable', 'reference' => 'opaque', 'source' => null, 'size_bytes' => 0, 'size_estimated' => false, 'metadata' => []];
}

test('Tempest discovery rules accept coherent empty nullable and terminal coverage values', function (): void {
    $validator = new GenericContainer()->get(DiscoveryBatchValidator::class);
    $item = validation_discovered_item();
    $item['size_bytes'] = null;
    $finishes = [
        ['kind' => 'exhaustive', 'refresh_state' => null],
        ['kind' => 'partial', 'deficiencies' => [['disposition' => 'unknown', 'diagnostic' => ['message' => 'Known gap', 'evidence' => []]]]],
        ['kind' => 'indeterminate', 'diagnostic' => ['message' => 'Coverage unknown', 'evidence' => []]],
    ];
    foreach ($finishes as $finish) {
        $validator->validate(['items' => [], 'progress' => ['finished' => $finish]], 100);
        $validator->validate(['items' => [$item], 'progress' => ['finished' => $finish]], 100);
    }
    $validator->validate(['items' => [$item], 'progress' => ['more' => 'opaque-continuation']], 100);
    expect(true)->toBeTrue();
});

test('Tempest discovery rules reject missing nullable keys coercible scalars and malformed envelopes', function (): void {
    $validator = new GenericContainer()->get(DiscoveryBatchValidator::class);
    $progress = ['finished' => ['kind' => 'exhaustive', 'refresh_state' => null]];
    $item = validation_discovered_item();
    foreach (['source', 'size_bytes', 'size_estimated', 'metadata'] as $field) {
        $missing = $item;
        unset($missing[$field]);
        expect(fn () => $validator->validate(['items' => [$missing], 'progress' => $progress], 100))->toThrow(RuntimeException::class);
    }
    foreach ([
        ['size_bytes', '0'],
        ['size_bytes', 0.0],
        ['size_bytes', -1],
        ['size_estimated', 'false'],
        ['size_estimated', 0],
        ['size_estimated', 1],
        ['reference', new SplFileInfo('opaque')],
        ['id', ''],
        ['metadata', [['schema' => 'facet-without-json']]],
        ['metadata', ['not-a-list' => 'data']],
    ] as [$field, $value]) {
        $invalid = [...$item, $field => $value];
        expect(fn () => $validator->validate(['items' => [$invalid], 'progress' => $progress], 100))->toThrow(RuntimeException::class);
    }
    expect(fn () => $validator->validate(['items' => [[...$item, 'size_bytes' => null, 'size_estimated' => true]], 'progress' => $progress], 100))
        ->toThrow(RuntimeException::class);
    expect(fn () => $validator->validate(['items' => array_fill(0, 101, $item), 'progress' => $progress], 100))->toThrow(RuntimeException::class);
    foreach ([
        ['more' => ''],
        ['more' => 'cursor', ...$progress],
        ['finished' => ['kind' => 'exhaustive']],
        ['finished' => ['kind' => 'partial', 'deficiencies' => []]],
        ['finished' => ['kind' => 'partial', 'deficiencies' => [], 'refresh_state' => null]],
        ['finished' => ['kind' => 'indeterminate', 'diagnostic' => ['message' => 'missing evidence']]],
        ['finished' => ['kind' => 'unrecognized', 'refresh_state' => null]],
    ] as $invalidProgress) {
        expect(fn () => $validator->validate(['items' => [], 'progress' => $invalidProgress], 100))->toThrow(RuntimeException::class);
    }
});

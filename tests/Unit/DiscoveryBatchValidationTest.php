<?php

use App\Integration\DiscoveryBatchValidator;
use Tempest\Core\FrameworkKernel;

use function Tempest\Container\get;

beforeAll(static function (): void {
    FrameworkKernel::boot(root: dirname(__DIR__, 2));
});

/**
 * Supply a canonical Item observation for focused protocol value rejection tests.
 */
function validation_discovered_item(): array
{
    return ['id' => 'stable', 'reference' => 'opaque', 'source' => null, 'size_bytes' => 0, 'size_estimated' => false, 'metadata' => []];
}

test('Tempest discovery rules accept coherent empty nullable and terminal coverage values', function (): void {
    $validator = get(DiscoveryBatchValidator::class);
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
    $validator = get(DiscoveryBatchValidator::class);
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
        ['source', '[]'],
        ['metadata', [['schema' => 'facet-without-json']]],
        ['metadata', ['not-a-list' => 'data']],
    ] as [$field, $value]) {
        $invalid = [...$item, $field => $value];
        expect(fn () => $validator->validate(['items' => [$invalid], 'progress' => $progress], 100))->toThrow(RuntimeException::class);
    }
    expect(fn () => $validator->validate(['items' => [[...$item, 'source.uri' => 'injected']], 'progress' => $progress], 100))->toThrow(RuntimeException::class);
    expect(fn () => $validator->validate(['items' => [[...$item, 'size_estimated.extra' => true]], 'progress' => $progress], 100))->toThrow(RuntimeException::class);
    expect(fn () => $validator->validate(['items' => [$item], 'progress' => $progress, 'items.0.size_bytes' => '1'], 100))->toThrow(RuntimeException::class);
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

test('typed Filesystem cursors retain exact positions and reject coercion missing fields and unsafe frame paths', function (): void {
    $validator = get(\Tempest\Validation\Validator::class);
    $roots = get(\App\Integration\FilesystemRoots::class);
    $values = ['version' => 1, 'frames' => [['path' => 'nested/資料', 'position' => 100]], 'skipped' => 1, 'first' => 'Known gap', 'retryable' => false];
    $cursor = \App\Integration\FilesystemCursor::decode(json_encode($values, JSON_THROW_ON_ERROR), $validator, $roots);
    expect($cursor->frames[0])
        ->toBeInstanceOf(\App\Integration\FilesystemFrame::class)
        ->and($cursor->frames[0]->path)
        ->toBe('nested/資料')
        ->and($cursor->frames[0]->position)
        ->toBe(100);
    expect(\Tempest\Mapper\map($cursor)->toArray())->toBe($values);
    $missing = $values;
    unset($missing['first']);
    $badValues = [
        [...$values, 'frames.0' => ['path' => '', 'position' => 0]],
        $missing,
        [...$values, 'version' => '1'],
        [...$values, 'skipped' => '0'],
        [...$values, 'first' => null],
        [...$values, 'retryable' => 0],
    ];
    foreach (['../outside', '/absolute', 'nested//empty', 'nested/./dot', 'back\\slash', "new\nline"] as $path) {
        $badValues[] = [...$values, 'frames' => [['path' => $path, 'position' => 1]]];
    }
    foreach (['1', -1, 1.0] as $position) {
        $badValues[] = [...$values, 'frames' => [['path' => 'nested', 'position' => $position]]];
    }
    foreach ($badValues as $invalid) {
        expect(fn () => \App\Integration\FilesystemCursor::decode(json_encode($invalid, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $validator, $roots))
            ->toThrow(InvalidArgumentException::class);
    }
});

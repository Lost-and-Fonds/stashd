<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\OcflInspector;

test('unsafe logical paths are rejected before any Vault write and can safely retry', function (string $logicalPath, bool $update): void {
    $this->archive->run('vault.initialize');
    $arguments = [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174210',
        'files' => ['original.bin' => ['contents' => 'original']],
    ];
    $created = $update ? $this->archive->run('vault.object_create', $arguments) : null;
    $snapshot = function (): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->archive->path('vault'),
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            $files[$file->getPathname()] = $file->isFile() ? hash_file('sha256', $file->getPathname()) : null;
        }
        ksort($files);
        return $files;
    };
    $before = $snapshot();
    $operation = $update ? 'vault.object_update' : 'vault.object_create';
    $write = $update ? ['object_id' => $created['object_id']] : $arguments;

    expect(fn () => $this->archive->run($operation, [
        ...$write,
        'files' => [
            'valid.bin' => ['contents' => 'must not be committed'],
            $logicalPath => ['contents' => 'unsafe'],
        ],
    ]))
        ->toThrow(InvalidArgumentException::class);
    expect($snapshot())->toBe($before);

    $retried = $this->archive->run($operation, [
        ...$write,
        'files' => ['safe/nested.bin' => ['contents' => 'retried']],
    ]);
    expect($retried['version'])->toBe($update ? 'v2' : 'v1');
    expect(file_get_contents(OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $retried['object_id'],
        $retried['version'],
        'safe/nested.bin',
    )))
        ->toBe('retried');
    if ($update) {
        expect(file_get_contents(OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $created['object_id'],
            'v1',
            'original.bin',
        )))
            ->toBe('original');
    }
})->with([
    'parent traversal' => ['../escape.bin'],
    'nested traversal' => ['nested/../../escape.bin'],
    'inventory overwrite' => ['../../inventory.json'],
    'absolute' => ['/absolute.bin'],
    'UNC' => ['//server/share.bin'],
    'drive absolute' => ['C:/absolute.bin'],
    'drive relative' => ['C:relative.bin'],
    'backslash' => ['nested\\escape.bin'],
    'empty segment' => ['nested//file.bin'],
    'trailing slash' => ['nested/'],
    'dot segment' => ['nested/./file.bin'],
    'dot' => ['.'],
    'parent' => ['..'],
    'empty' => [''],
    'NUL' => ["bad\0file.bin"],
])->with([false, true]);

test('rejected acquisition sources leave no committed version and preserve history on retry', function (string $kind, bool $update): void {
    $this->archive->run('vault.initialize');
    $source = $this->archive->input('real/source.bin', 'source');
    $rejected = match ($kind) {
        'missing' => $this->archive->path('inputs/missing.bin'),
        'symlink', 'dangling' => $this->archive->path('inputs/link.bin'),
        'ancestor' => $this->archive->path('inputs/linked/source.bin'),
        default => throw new InvalidArgumentException('Unknown source fixture.'),
    };
    if ($kind === 'symlink' || $kind === 'dangling') {
        symlink($kind === 'dangling' ? $source . '.missing' : $source, $rejected);
    } elseif ($kind === 'ancestor') {
        symlink(dirname($source), dirname($rejected));
    }
    $arguments = [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174211',
        'files' => ['original.bin' => ['contents' => 'original']],
    ];
    $created = $update ? $this->archive->run('vault.object_create', $arguments) : null;
    $before = $update ? OcflInspector::inventoryFor($this->archive->path('vault'), $created['object_id']) : null;
    $inventoryBytes = $update ? file_get_contents($before['path']) : null;
    $operation = $update ? 'vault.object_update' : 'vault.object_create';
    $write = $update ? ['object_id' => $created['object_id']] : $arguments;

    expect(fn () => $this->archive->run($operation, [
        ...$write,
        'files' => ['valid.bin' => ['contents' => 'not committed'], 'source.bin' => $rejected],
    ]))
        ->toThrow(RuntimeException::class);
    if ($update) {
        expect(file_get_contents($before['path']))->toBe($inventoryBytes);
        OcflInspector::assertInventorySidecar($before['path']);
        expect(is_dir(dirname($before['path']) . '/v2'))->toBeFalse();
        expect(file_get_contents(OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $created['object_id'],
            'v1',
            'original.bin',
        )))
            ->toBe('original');
    } else {
        $digest = hash('sha256', 'urn:stashd:asset:' . $arguments['uuid']);
        $objectRoot = $this->archive->path('vault/' . substr($digest, 0, 2) . '/' . substr($digest, 2, 2) . '/' . $digest);
        expect(is_file($objectRoot . '/inventory.json'))->toBeFalse();
        expect(is_dir($objectRoot . '/v1'))->toBeFalse();
    }
    $retried = $this->archive->run($operation, [
        ...$write,
        'files' => ['safe.bin' => $source],
    ]);
    expect($retried['version'])->toBe($update ? 'v2' : 'v1');
    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $retried['object_id']);
    OcflInspector::assertInventorySidecar($found['path']);
    expect(file_get_contents(OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $retried['object_id'],
        $retried['version'],
        'safe.bin',
    )))
        ->toBe('source');
    expect(fn () => OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $retried['object_id'],
        $retried['version'],
        'valid.bin',
    ))
        ->toThrow(RuntimeException::class);
})->with(['missing', 'symlink', 'dangling', 'ancestor'])->with([false, true]);

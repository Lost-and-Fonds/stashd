<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordsAndArchives\ArchivesRejected;
use Tests\Support\RecordsAndArchives\ArchiveTestCase;
use Tests\Support\RecordsAndArchives\OcflInspector;

final class ItemRecordTest extends ArchiveTestCase
{
    public function testItemHistoryIsIndependentlyRecoverableFromInventoriesAndExactHistoricalPayloads(): void
    {
        $this->archive->run('vault.initialize');
        $uri = 'urn:example:book:metadata-only';
        $first = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Original', 'opaque' => ['value' => 1.0]]]);
        $firstInventory = OcflInspector::inventoryFor($this->archive->path('vault'), $first['object_id']);
        $this->assertFileExists(dirname($firstInventory['path']) . '/v1/inventory.json');
        OcflInspector::assertInventorySidecar(dirname($firstInventory['path']) . '/v1/inventory.json');
        $originalInventory = file_get_contents(dirname($firstInventory['path']) . '/v1/inventory.json');
        $second = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Revised']]);
        $this->archive->run('vault.open');

        expect($first['object_id'])
            ->toBe('urn:stashd:item:af8b11f8-60a7-5da8-b6d4-11d6ab23e2d5')
            ->and($first['object_id'])
            ->toBe($second['object_id'])
            ->and($first['version'])
            ->toBe('v1')
            ->and($second['version'])
            ->toBe('v2');

        $found = OcflInspector::inventoryFor($this->archive->path('vault'), $first['object_id']);
        /** @var array{digestAlgorithm: string, versions: array<string, array{state: array<string, array>}>} $inventory */
        $inventory = $found['inventory'];
        OcflInspector::assertInventorySidecar($found['path']);
        expect(array_keys($inventory['versions']))
            ->toBe(['v1', 'v2'])
            ->and(file_get_contents(dirname($found['path']) . '/v1/inventory.json'))
            ->toBe($originalInventory);

        foreach (['v1' => 'Original', 'v2' => 'Revised'] as $version => $title) {
            $path = OcflInspector::payloadPath($this->archive->path('vault'), $first['object_id'], $version, 'item.json');
            $bytes = file_get_contents($path);
            $this->assertIsString($bytes);
            /** @var array{format: string, identity_policy: string, object_id: string, uri: string, pins: array, metadata: array} $record */
            $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            expect($record['format'])
                ->toBe('stashd-item')
                ->and($record['identity_policy'])
                ->toBe('exact-uri-v1')
                ->and($record['object_id'])
                ->toBe($first['object_id'])
                ->and($record['uri'])
                ->toBe($uri)
                ->and($record['pins'])
                ->toBe([])
                ->and($record['metadata']['title'])
                ->toBe($title)
                ->and(hash_file($inventory['digestAlgorithm'], $path))
                ->toBe(array_search(['item.json'], $inventory['versions'][$version]['state'], true));
            $read = $this->archive->run('item.read', ['object_id' => $first['object_id'], 'version' => $version]);
            expect($read['metadata'])->toBe($record['metadata']);
        }

        $latest = $this->archive->run('item.read', ['object_id' => $first['object_id']]);
        $original = $this->archive->run('item.read', ['object_id' => $first['object_id'], 'version' => 'v1']);
        $this->assertIsArray($latest['metadata']);
        $this->assertIsArray($original['metadata']);
        $this->assertIsArray($original['metadata']['opaque']);
        expect($latest['metadata']['title'])
            ->toBe('Revised')
            ->and($original['metadata']['opaque']['value'])
            ->toBe(1.0)
            ->and($this->archive->run('vault.list_objects')['object_ids'])
            ->toBe([$first['object_id']]);
    }

    public function testItemIdentityPreservesDistinctionsInCanonicalURIBytes(): void
    {
        $this->archive->run('vault.initialize');
        $uris = [
            'https://example.test/a',
            'https://EXAMPLE.test/a',
            'https://example.test:443/a',
            'https://example.test/%61',
            'https://example.test/a/',
            'https://example.test/a#one',
            'https://example.test/a?x=1&y=2',
            'https://example.test/a?y=2&x=1',
            'urn:example:Case',
            'urn:example:case',
        ];
        $ids = [];
        foreach ($uris as $uri) {
            $item = $this->archive->run('item.record', ['uri' => $uri]);
            $ids[] = $item['object_id'];
            expect($this->archive->run('item.read', ['object_id' => $item['object_id']])['uri'])->toBe($uri);
        }
        expect(array_unique($ids))->toHaveCount(count($uris));
    }

    #[DataProvider('invalidUris')]
    public function testInvalidCanonicalURIsDoNotCreateObjects(string $uri): void
    {
        $this->archive->run('vault.initialize');
        expect(fn () => $this->archive->run('item.record', ['uri' => $uri]))->toThrow(ArchivesRejected::class);
        expect($this->archive->run('vault.list_objects')['object_ids'])->toBe([]);
    }

    public function testInvalidMetadataAndUnsupportedPinsCannotModifyCommittedItemHistory(): void
    {
        $this->archive->run('vault.initialize');
        $uri = 'urn:example:safety';
        $item = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Keep']]);
        $found = OcflInspector::inventoryFor($this->archive->path('vault'), $item['object_id']);
        $before = file_get_contents($found['path']);
        foreach ([['metadata' => ['bad' => NAN]], ['metadata' => ['bad' => new stdClass()]], ['pins' => [['object_id' => 'missing', 'version' => 'v1']]]] as $invalid) {
            expect(fn () => $this->archive->run('item.record', ['uri' => $uri, ...$invalid]))->toThrow(ArchivesRejected::class);
            expect(file_get_contents($found['path']))->toBe($before);
        }
        $read = $this->archive->run('item.read', ['object_id' => $item['object_id']]);
        $this->assertIsArray($read['metadata']);
        expect($read['metadata']['title'])->toBe('Keep');
    }

    public function testHistoricalItemReadsRejectMissingVersionsRatherThanFallingBackToLatest(): void
    {
        $this->archive->run('vault.initialize');
        $item = $this->archive->run('item.record', ['uri' => 'urn:example:history']);
        expect(fn () => $this->archive->run('item.read', ['object_id' => $item['object_id'], 'version' => 'v999']))->toThrow(OutOfBoundsException::class);
    }

    public function testTheSameCanonicalURIHasReproducibleIdentityInIndependentVaults(): void
    {
        $this->archive->run('vault.initialize');
        $first = $this->archive->run('item.record', ['uri' => 'urn:example:book:metadata-only']);
        $otherRoot = $this->archive->path('independent-vault');
        $this->archive->run('vault.initialize', ['primary' => $otherRoot]);
        $second = $this->archive->run('item.record', ['primary' => $otherRoot, 'uri' => 'urn:example:book:metadata-only']);
        expect($second['object_id'])->toBe($first['object_id']);
    }

    public function testItemRecordsCannotAppendToAnUnrelatedObjectAtTheDeterministicIdentity(): void
    {
        $this->archive->run('vault.initialize');
        $object = $this->archive->run('vault.object_create', [
            'type' => 'item',
            'uuid' => 'af8b11f8-60a7-5da8-b6d4-11d6ab23e2d5',
            'files' => ['item.json' => ['contents' => '{"format":"unrelated"}']],
        ]);
        $found = OcflInspector::inventoryFor($this->archive->path('vault'), $object['object_id']);
        $before = file_get_contents($found['path']);
        expect(fn () => $this->archive->run('item.record', ['uri' => 'urn:example:book:metadata-only']))->toThrow(UnexpectedValueException::class);
        expect(file_get_contents($found['path']))->toBe($before);
    }

    public static function invalidUris(): array
    {
        return array_map(static fn (string $uri): array => [$uri], [
            '',
            '/relative',
            'example.test/item',
            ' https://example.test/a',
            "urn:example:a\n",
            'https://example.test/%no',
            'https://example.test/a b',
            'https://example.test/é',
            'https://[invalid/a',
            'urn:example:a\\b',
        ]);
    }
}

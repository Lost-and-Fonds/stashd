<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordsAndArchives\ArchivesRejected;
use Tests\Support\RecordsAndArchives\ArchiveTestCase;
use Tests\Support\RecordsAndArchives\OcflInspector;

final class ItemArchiveTest extends ArchiveTestCase
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
            /** @var array{pins: list<array{object_id: string, version: string}>, metadata: array} $read */
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

    public function testInvalidMetadataAndPinsCannotModifyCommittedItemHistory(): void
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

    public function testItemArchiveCannotAppendToAnUnrelatedObjectAtTheDeterministicIdentity(): void
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

    public function testPinsPersistInPhysicalItemHistoryAndRetainExactHistoricalBytes(): void
    {
        $this->archive->run('vault.initialize');
        $uri = 'urn:example:pinned-history';
        $first = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Known']]);
        $source = $this->archive->input('binary', "first\0payload\xff");
        $digest1 = hash_file('sha512', $source);
        $asset1 = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['binary' => $source]]);
        $pins1 = [['object_id' => $asset1['object_id'], 'version' => 'v1']];
        $second = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'First'], 'pins' => $pins1]);
        $oldInventory = OcflInspector::inventoryFor($this->archive->path('vault'), $first['object_id']);
        $oldVersionInventory = file_get_contents(dirname($oldInventory['path']) . '/v2/inventory.json');
        file_put_contents($source, "second\0payload\xfe");
        $digest2 = hash_file('sha512', $source);
        $asset2 = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['binary' => $source]]);
        // Asset publication must not automatically advance Item history.
        expect($this->archive->run('item.read', ['object_id' => $first['object_id']])['pins'])->toBe($pins1);
        $pins2 = [['object_id' => $asset2['object_id'], 'version' => 'v2']];
        $third = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Second'], 'pins' => $pins2]);
        unlink($source);
        expect([$first['version'], $second['version'], $third['version']])->toBe(['v1', 'v2', 'v3']);
        $this->archive->run('vault.open');
        foreach ([
            ['v2', $pins1, $digest1, 'First'],
            ['v3', $pins2, $digest2, 'Second'],
        ] as [$version, $pins, $digest, $title]) {
            /** @var array{pins: list<array{object_id: string, version: string}>, metadata: array} $read */
            $read = $this->archive->run('item.read', ['object_id' => $first['object_id'], 'version' => $version]);
            expect($read['pins'])->toBe($pins)->and($read['metadata']['title'])->toBe($title);
            $payload = OcflInspector::payloadPath($this->archive->path('vault'), $first['object_id'], $version, 'item.json');
            $itemBytes = file_get_contents($payload);
            $this->assertIsString($itemBytes);
            /** @var array{pins: array} $stored */
            $stored = json_decode($itemBytes, true, flags: JSON_THROW_ON_ERROR);
            expect($stored['pins'])->toBe($pins);
            /** @var array{path: string, inventory: array{versions: array<string, array{state: array<string, list<string>>}>}} $inventory */
            $inventory = OcflInspector::inventoryFor($this->archive->path('vault'), $first['object_id']);
            expect($inventory['inventory']['versions'][$version]['state'][hash_file('sha512', $payload)])->toContain('item.json');
            OcflInspector::assertInventorySidecar(dirname($inventory['path']) . '/' . $version . '/inventory.json');
            $pin = $read['pins'][0];
            $bytes = $this->archive->run('asset.read', ['object_id' => $pin['object_id'], 'version' => $pin['version'], 'logical_path' => 'binary'])['bytes'];
            expect(hash('sha512', $bytes))
                ->toBe($digest)
                ->and(hash_file('sha512', OcflInspector::payloadPath($this->archive->path('vault'), $pin['object_id'], $pin['version'], 'binary')))
                ->toBe($digest);
        }
        expect(file_get_contents(dirname($oldInventory['path']) . '/v2/inventory.json'))
            ->toBe($oldVersionInventory)
            ->and($this->archive->run('item.read', ['object_id' => $first['object_id'], 'version' => 'v1'])['pins'])
            ->toBe([])
            ->and($this->archive->run('item.read', ['object_id' => $first['object_id']])['pins'])
            ->toBe($pins2);
    }

    #[DataProvider('invalidPins')]
    public function testMalformedPinsCannotModifyExistingItemOrAssetHistory(array $pins): void
    {
        $this->archive->run('vault.initialize');
        $uri = 'urn:example:invalid-pins';
        $item = $this->archive->run('item.record', ['uri' => $uri]);
        $asset = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['a' => $this->archive->input('a', 'A')]]);
        $valid = ['object_id' => $asset['object_id'], 'version' => 'v1'];
        $pins = array_map(static fn ($pin) => $pin === 'valid' ? $valid : $pin, $pins);
        $found = OcflInspector::inventoryFor($this->archive->path('vault'), $item['object_id']);
        $assetFound = OcflInspector::inventoryFor($this->archive->path('vault'), $asset['object_id']);
        $before = file_get_contents($found['path']);
        $assetBefore = file_get_contents($assetFound['path']);
        expect(fn () => $this->archive->run('item.record', ['uri' => $uri, 'pins' => $pins]))->toThrow(ArchivesRejected::class);
        expect(file_get_contents($found['path']))
            ->toBe($before)
            ->and(file_get_contents($assetFound['path']))
            ->toBe($assetBefore)
            ->and($this->archive->run('item.read', ['object_id' => $item['object_id']])['pins'])
            ->toBe([])
            ->and(is_dir(dirname($found['path']) . '/v2'))
            ->toBeFalse();
    }

    public static function invalidPins(): array
    {
        $id = 'urn:stashd:asset:00000000-0000-4000-8000-000000000001';
        return array_map(static fn (array $pins): array => [$pins], [
            ['valid', 'valid'],
            ['key' => 'valid'],
            [null],
            ['string'],
            [[]],
            [['object_id' => $id]],
            [['version' => 'v1']],
            [['object_id' => 123, 'version' => 'v1']],
            [['object_id' => $id, 'version' => 1]],
            [['object_id' => str_replace(':asset:', ':item:', $id), 'version' => 'v1']],
            [['object_id' => 'urn:stashd:asset:bad', 'version' => 'v1']],
            [['object_id' => $id, 'version' => 'latest']],
            [['object_id' => $id, 'version' => 'v0']],
            [['object_id' => $id, 'version' => 'v01']],
            [['object_id' => $id, 'version' => '../v1']],
            [['object_id' => $id, 'version' => 'v1', 'untrusted' => true]],
            ['valid', ['object_id' => $id, 'version' => 'v1']],
        ]);
    }

    public function testMultipleOwnedAssetsAreVerifiedAndForeignMissingOrIncompatiblePinsAreRejectedTogether(): void
    {
        $this->archive->run('vault.initialize');
        $uri = 'urn:example:multiple-pins';
        $item = $this->archive->run('item.record', ['uri' => $uri]);
        $pins = [];
        foreach (['master', 'thumbnail'] as $key) {
            $asset = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => $key, 'files' => ['a' => $this->archive->input($key, $key)]]);
            $pins[] = ['object_id' => $asset['object_id'], 'version' => 'v1'];
        }
        $this->archive->run('item.record', ['uri' => $uri, 'pins' => $pins]);
        expect($this->archive->run('item.read', ['object_id' => $item['object_id']])['pins'])->toBe($pins);
        $foreign = $this->archive->run('asset.preserve', [
            'item_uri' => 'urn:example:foreign',
            'asset_key' => 'master',
            'files' => ['a' => $this->archive->input('foreign', 'foreign')],
        ]);
        $incompatible = $this->archive->run('vault.object_create', [
            'type' => 'asset',
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'files' => ['.stashd/asset.json' => ['contents' => '{"format":"incompatible"}']],
        ]);
        $itemInventory = OcflInspector::inventoryFor($this->archive->path('vault'), $item['object_id']);
        $before = file_get_contents($itemInventory['path']);
        foreach ([
            ['object_id' => $foreign['object_id'], 'version' => 'v1'],
            ['object_id' => $incompatible['object_id'], 'version' => 'v1'],
            ['object_id' => $pins[0]['object_id'], 'version' => 'v999'],
        ] as $bad) {
            expect(fn () => $this->archive->run('item.record', ['uri' => $uri, 'pins' => [$pins[1], $bad]]))->toThrow(ArchivesRejected::class);
            expect(file_get_contents($itemInventory['path']))->toBe($before);
        }
    }

    public function testIntegrityChecksUseTheExactPinnedVersionAndFailedItemCommitRetainsUnlinkedAsset(): void
    {
        $this->archive->run('vault.initialize');
        $uri = 'urn:example:pin-integrity';
        $item = $this->archive->run('item.record', ['uri' => $uri]);
        $source = $this->archive->input('revision', 'first');
        $asset = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['a' => $source]]);
        file_put_contents($source, 'second');
        $second = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['a' => $source]]);
        $pins1 = [['object_id' => $asset['object_id'], 'version' => 'v1']];
        $pins2 = [['object_id' => $asset['object_id'], 'version' => 'v2']];
        // Valid Asset v2 survives rejection of the separately proposed Item revision.
        expect(fn () => $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['invalid' => NAN], 'pins' => $pins2]))->toThrow(ArchivesRejected::class);
        expect($this->archive->run('asset.read', ['object_id' => $asset['object_id'], 'version' => 'v2', 'logical_path' => 'a'])['bytes'])->toBe('second');
        file_put_contents(OcflInspector::payloadPath($this->archive->path('vault'), $asset['object_id'], 'v2', 'a'), 'corrupt');
        $this->archive->run('item.record', ['uri' => $uri, 'pins' => $pins1]);
        $inventory = OcflInspector::inventoryFor($this->archive->path('vault'), $item['object_id']);
        $before = file_get_contents($inventory['path']);
        expect(fn () => $this->archive->run('item.record', ['uri' => $uri, 'pins' => $pins2]))->toThrow(ArchivesRejected::class);
        expect(file_get_contents($inventory['path']))
            ->toBe($before)
            ->and($this->archive->run('item.read', ['object_id' => $item['object_id']])['pins'])
            ->toBe($pins1)
            ->and($second['version'])
            ->toBe('v2');
    }

    public function testInvalidFirstPinDoesNotCreateAnItemAndCorruptItemPayloadCannotInventPins(): void
    {
        $this->archive->run('vault.initialize');
        expect(fn () => $this->archive->run('item.record', ['uri' => 'urn:example:invalid-first', 'pins' => [['object_id' => 'missing', 'version' => 'v1']]]))
            ->toThrow(ArchivesRejected::class);
        expect($this->archive->run('vault.list_objects')['object_ids'])->toBe([]);
        $item = $this->archive->run('item.record', ['uri' => 'urn:example:tampered']);
        $payload = OcflInspector::payloadPath($this->archive->path('vault'), $item['object_id'], 'v1', 'item.json');
        $itemBytes = file_get_contents($payload);
        $this->assertIsString($itemBytes);
        $record = json_decode($itemBytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($record);
        $record['pins'] = [['object_id' => 'urn:stashd:asset:00000000-0000-4000-8000-000000000001', 'version' => 'v1']];
        file_put_contents($payload, json_encode($record, JSON_THROW_ON_ERROR));
        expect(fn () => $this->archive->run('item.read', ['object_id' => $item['object_id'], 'version' => 'v1']))
            ->toThrow(UnexpectedValueException::class, 'differs from its OCFL inventory');
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

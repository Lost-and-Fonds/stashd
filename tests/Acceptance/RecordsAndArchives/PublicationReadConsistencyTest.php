<?php

use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use App\RecordsAndArchives\Vault;
use League\Uri\Urn;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;
use Tempest\Storage\Storage;
use Tests\Support\RecordsAndArchives\OcflInspector;

test('a root inventory replaced during reading cannot authenticate different parsed bytes', function (): void {
    $this->archive->run('vault.initialize');
    $first = $this->archive->run('item.record', ['uri' => 'https://example.test/interleaving', 'metadata' => ['revision' => 'first']]);
    $this->archive->run('item.record', ['uri' => 'https://example.test/interleaving', 'metadata' => ['revision' => 'second']]);
    $root = $this->archive->path('vault');
    $found = OcflInspector::inventoryFor($root, $first['object_id']);
    $inventory = $found['path'];
    $objectRoot = dirname($inventory);
    $next = file_get_contents($inventory);
    $previous = file_get_contents($objectRoot . '/v1/inventory.json');
    file_put_contents($inventory . '.next', $next);
    file_put_contents($inventory, $previous);
    copy($objectRoot . '/v1/inventory.json.sha512', $inventory . '.sha512');
    $published = false;
    $publish = function () use (&$published, $inventory): void {
        if (! $published) {
            $published = true;
            $this->assertTrue(rename($inventory . '.next', $inventory));
        }
    };
    $config = new LocalStorageConfig(path: $root, tag: 'vault');
    // Both hooks return real bytes from the old inode, then publish the replacement.
    $storage = new readonly class(new GenericStorage($config), $config, substr($inventory, strlen($root) + 1), $publish) extends LocalArchiveStorage {
        public function __construct(
            Storage $storage,
            LocalStorageConfig $config,
            private string $inventory,
            private Closure $publish,
        ) {
            parent::__construct($storage, $config);
        }

        public function readStream(string $location): mixed
        {
            $stream = parent::readStream($location);
            if ($location === $this->inventory) {
                ($this->publish)();
            }
            return $stream;
        }

        public function read(string $location): string
        {
            $bytes = parent::read($location);
            if ($location === $this->inventory) {
                ($this->publish)();
            }
            return $bytes;
        }
    };
    $vault = new Vault($storage);
    $id = Urn::fromString($first['object_id']);
    $object = $vault->getObject($id);

    expect($published)->toBeTrue();
    expect(file_get_contents($inventory))->toBe($next);
    expect($object->head())->toBe('v1');
    expect($object->versionNames())->toBe(['v1']);
    expect(json_decode($vault->readObjectContent($id, 'v1', 'item.json'), true, flags: JSON_THROW_ON_ERROR)['metadata'])->toBe(['revision' => 'first']);
    expect(fn () => $vault->readObjectContent($id, 'v2', 'item.json'))->toThrow(OutOfBoundsException::class);
    OcflInspector::assertAcknowledgedHistory($root, $first['object_id'], 'v1');
    $state = $vault->inspectObject($id);
    expect($state['head'])->toBe('v1');
    expect($state['issues'])->not->toBeEmpty();
});

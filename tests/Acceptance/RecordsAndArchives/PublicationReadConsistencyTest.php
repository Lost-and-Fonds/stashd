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
    $storage = new /**
     * Publish a replacement root inventory immediately after reading its old inode.
     *
     * Both buffered and stream hooks retain real local bytes, creating a deterministic
     * reader/publication interleaving without replacing the archival implementation.
     */ readonly class(new GenericStorage($config), $config, substr($inventory, strlen($root) + 1), $publish) extends LocalArchiveStorage {
        /**
         * Bind real local storage, the intercepted inventory path and the publication hook.
         */
        public function __construct(
            Storage $storage,
            LocalStorageConfig $config,
            private string $inventory,
            private Closure $publish,
        ) {
            parent::__construct($storage, $config);
        }

        /**
         * Open the old inventory stream before triggering replacement of the root path.
         *
         * The caller owns the stream, which continues to refer to its original inode.
         */
        public function readStream(string $location): mixed
        {
            $stream = parent::readStream($location);
            if ($location === $this->inventory) {
                ($this->publish)();
            }

            return $stream;
        }

        /**
         * Read the original inventory bytes before triggering the replacement hook.
         *
         * Returns the captured bytes even though the root path may now contain a newer inventory.
         */
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

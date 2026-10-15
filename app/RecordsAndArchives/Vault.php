<?php

namespace App\RecordsAndArchives;

use App\RecordsAndArchives\Storage\OcflFilesystemAdapter;
use App\RecordsAndArchives\Storage\StashdStorage;
use Exception;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;
use Symfony\Component\Uid\Uuid;
use Tempest\Clock\Clock;
use Tempest\Container\Singleton;
use Tempest\Container\Tag;
use function Tempest\Mapper\map;

#[Singleton]
class Vault
{
    private const string IDENTITY_FILENAME = 'identity.json';
    private ?VaultIdentity $identity = null;

    public function __construct(
        #[Tag('vault')]
        private readonly StashdStorage $storage,
        private readonly Clock $clock,
    ) {}

    public function open(): StorageRoot
    {
        if (!$this->storage->fileExists(self::IDENTITY_FILENAME)) {
            throw new Exception('Vault identity does not exist');
        }

        $this->identity = map($this->storage->read(self::IDENTITY_FILENAME))->to(VaultIdentity::class);

        return StorageRoot::open(
            path: '',
            fs: new OcflFilesystemAdapter($this->storage)
        );
    }

    public function create(): void
    {
        $layout = new HashedNTupleStorageLayout(
            digestAlgorithm: DigestAlgorithm::Sha256,
            tupleSize: 2,
            numberOfTuples: 2,
            shortObjectRoot: false,
        );

        StorageRoot::create(
            path: '',
            layout: $layout,
            fs: new OcflFilesystemAdapter($this->storage)
        );

        $vaultIdentity = new VaultIdentity(
            format: 'stashd-vault',
            version: 1,
            identifier: 'urn:uuid:' . Uuid::v4()->toRfc4122(),
            createdAt: $this->clock->now()->toIso8601String()
        );

        $this->storage->write(self::IDENTITY_FILENAME, map($vaultIdentity)->toJson());

        $this->identity = $vaultIdentity;
    }

    public function getIdentity(): ?VaultIdentity
    {
        return $this->identity;
    }
}
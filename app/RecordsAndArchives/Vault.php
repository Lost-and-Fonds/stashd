<?php

namespace App\RecordsAndArchives;

use App\RecordsAndArchives\Storage\ArchiveStorage;
use App\RecordsAndArchives\Storage\OcflFilesystemAdapter;
use Exception;
use League\Uri\Urn;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot;
use Symfony\Component\Uid\Uuid;
use Tempest\Container\Singleton;
use Tempest\Container\Tag;
use function Tempest\Clock\now;
use function Tempest\Mapper\map;

#[Singleton]
class Vault
{
    private const string IDENTITY_FILENAME = 'identity.json';
    private const string VAULT_FORMAT = 'stashd-vault';
    private const int VAULT_VERSION = 1;
    private ?VaultIdentity $identity = null;

    public function __construct(
        #[Tag('vault')]
        private readonly ArchiveStorage $storage
    ) {}

    public function open(): StorageRoot
    {
        if (!$this->storage->fileExists(self::IDENTITY_FILENAME)) {
            throw new Exception('Vault identity does not exist');
        }

        $identityData = $this->storage->read(self::IDENTITY_FILENAME);

        $this->identity = map($identityData)->to(VaultIdentity::class);

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

        $this->identity = new VaultIdentity(
            format: self::VAULT_FORMAT,
            version: self::VAULT_VERSION,
            identifier: Urn::fromRfc2141(nid: 'uuid', nss: Uuid::v4()->toRfc4122()),
            createdAt: now()
        );

        $this->storage->write(self::IDENTITY_FILENAME, json_encode($this->identity));
    }

    public function getIdentity(): ?VaultIdentity
    {
        return $this->identity;
    }
}
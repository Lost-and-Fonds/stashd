<?php

namespace App\RecordsAndArchives;

use App\RecordsAndArchives\Storage\ArchiveStorage;
use App\RecordsAndArchives\Storage\OcflFilesystemAdapter;
use Exception;
use League\Uri\Urn;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot as OcflStorageRoot;
use Symfony\Component\Uid\Uuid;
use Tempest\Container\Singleton;
use Tempest\Container\Tag;
use function Tempest\Clock\now;

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

    public function open(): OcflStorageRoot
    {
        if (!$this->storage->fileExists(self::IDENTITY_FILENAME)) {
            throw new Exception('Vault identity does not exist');
        }

        $identityData = $this->storage->read(self::IDENTITY_FILENAME);

        $this->identity = VaultIdentity::fromJson($identityData);

        return OcflStorageRoot::open(
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

        OcflStorageRoot::create(
            path: '',
            layout: $layout,
            fs: new OcflFilesystemAdapter($this->storage)
        );

        $this->identity = new VaultIdentity(
            format: self::VAULT_FORMAT,
            version: self::VAULT_VERSION,
            identifier: $this->newObjectId(ObjectType::Vault),
            createdAt: now()
        );

        $this->storage->write(self::IDENTITY_FILENAME, json_encode($this->identity, JSON_THROW_ON_ERROR));
    }

    public function createObject(ObjectType $type, array $files): OcflObject
    {
        $object = $this->open()->createObject(
            id: $this->newObjectId($type)->toString()
        );

        $version = $object->newVersion();

        foreach ($files as $logicalPath => $sourcePath) {
            $version = $version->addFile($logicalPath, $sourcePath);
        }

        return $version->commit();
    }

    public function updateObject(Urn $objectId, array $files): OcflObject
    {
        $object = $this->open()->getObject(
            id: $objectId->toString()
        );

        $version = $object->newVersion();

        foreach ($files as $logicalPath => $sourcePath) {
            $version = $version->addFile($logicalPath, $sourcePath);
        }

        return $version->commit();
    }

    public function getIdentity(): ?VaultIdentity
    {
        return $this->identity;
    }

    public function newObjectId(ObjectType $type): Urn
    {
        return Urn::fromRfc2141(
            nid: 'stashd',
            nss: $type->value . ':' . Uuid::v4()->toRfc4122());
    }
}
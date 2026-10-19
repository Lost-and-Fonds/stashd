<?php

namespace App\RecordsAndArchives;

use App\RecordsAndArchives\Concurrency\ObjectWriteLock;
use App\RecordsAndArchives\Storage\ArchiveStorage;
use App\RecordsAndArchives\Storage\OcflFilesystemAdapter;
use Exception;
use InvalidArgumentException;
use League\Uri\Urn;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Storage\HashedNTupleStorageLayout;
use Ottosmops\Ocfl\Storage\StorageRoot as OcflStorageRoot;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use Ottosmops\Ocfl\VersionBuilder;
use RuntimeException;
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
    private readonly ObjectWriteLock $writeLock;

    public function __construct(
        #[Tag('vault')]
        private readonly ArchiveStorage $storage,
    ) {
        $this->writeLock = new ObjectWriteLock();
    }

    public function open(): OcflStorageRoot
    {
        if (! $this->storage->fileExists(self::IDENTITY_FILENAME)) {
            throw new Exception('Vault identity does not exist');
        }

        $identityData = $this->storage->read(self::IDENTITY_FILENAME);

        $this->identity = VaultIdentity::fromJson($identityData);

        return OcflStorageRoot::open(
            path: '',
            fs: new OcflFilesystemAdapter($this->storage),
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
            fs: new OcflFilesystemAdapter($this->storage),
        );

        $this->identity = new VaultIdentity(
            format: self::VAULT_FORMAT,
            version: self::VAULT_VERSION,
            identifier: $this->urnFromUuid(IdentifierType::Vault),
            createdAt: now(),
        );

        $this->storage->write(
            location: self::IDENTITY_FILENAME,
            contents: json_encode($this->identity, JSON_THROW_ON_ERROR),
        );
    }

    public function createObject(IdentifierType $type, ?Uuid $uuid = null, array $files = []): OcflObject
    {
        $this->validateFiles($files);
        $id = $this->urnFromUuid($type, $uuid);

        return $this->writeLock->exclusiveWrite($id, function () use ($id, $files): OcflObject {
            $root = $this->open();
            $object = $root->hasObject($id->toString())
                ? $root->getObject($id->toString())
                : $root->createObject(id: $id->toString());

            if ($object->id() !== $id->toString() || $object->head() !== '') {
                throw new OcflException(ErrorCode::E001, "Object '{$id}' already has committed history.");
            }

            $version = $object->newVersion();

            return $this->stageFiles($version, $files)->commit();
        });
    }

    public function updateObject(Urn $objectUrn, array $files): OcflObject
    {
        $this->validateFiles($files);

        return $this->writeLock->exclusiveWrite($objectUrn, function () use ($objectUrn, $files): OcflObject {
            $version = $this->open()->getObject(id: $objectUrn->toString())->newVersion();

            return $this->stageFiles($version, $files)->commit();
        });
    }

    private function validateFiles(array $files): void
    {
        if ($files === []) {
            throw new InvalidArgumentException('An OCFL version must contain at least one file change.');
        }

        foreach ($files as $logicalPath => $source) {
            if (
                ! is_string($logicalPath)
                || $logicalPath === ''
                || str_starts_with($logicalPath, '/')
                || str_contains($logicalPath, '\\')
                || preg_match('/^[a-zA-Z]:|[\x00-\x1f\x7f]/', $logicalPath)
                || array_intersect(explode('/', $logicalPath), ['', '.', '..']) !== []
            ) {
                throw new InvalidArgumentException('Logical file paths must be safe relative OCFL paths.');
            }

            if (! is_string($source) && ! (is_array($source) && isset($source['contents']) && is_string($source['contents']))) {
                throw new InvalidArgumentException('Each file must be a source path or a contents entry.');
            }

            if (is_string($source) && str_starts_with($source, '/') && (! is_file($source) || ! is_readable($source))) {
                throw new RuntimeException('Acquisition source must be a readable regular file.');
            }
        }
    }

    /** @param array<string, string|array{contents: string}> $files */
    private function stageFiles(VersionBuilder $version, array $files): VersionBuilder
    {
        foreach ($files as $logicalPath => $source) {
            if (is_string($source)) {
                $version->addFile($logicalPath, $source);
            } else {
                $version->addContents($logicalPath, $source['contents']);
            }
        }

        return $version;
    }

    public function readObjectContent(Urn $objectUrn, string $version, string $logicalPath): string
    {
        $stream = $this->readObjectStream($objectUrn, $version, $logicalPath);

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw new RuntimeException('Could not read archived content stream.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Open an exact-version file as a stream. The caller owns and must close it.
     *
     * @return resource
     */
    public function readObjectStream(Urn $objectUrn, string $version, string $logicalPath): mixed
    {
        $object = $this->open()->getObject(id: $objectUrn->toString());
        $physicalPath = $object->path . '/' . $object->resolveContentPath($version, $logicalPath);

        if (! $this->storage->fileExists($physicalPath)) {
            throw new OcflException(ErrorCode::E092, "Archived content missing at '{$physicalPath}'.");
        }

        $stream = $this->storage->readStream($physicalPath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not open archived content stream for '{$logicalPath}'.");
        }

        return $stream;
    }

    public function getIdentity(): ?VaultIdentity
    {
        return $this->identity;
    }

    public function urnFromUuid(IdentifierType $type, ?Uuid $uuid = null): Urn
    {
        $uuid ??= Uuid::v4();

        return Urn::fromRfc2141(
            nid: 'stashd',
            nss: $type->value . ':' . $uuid->toRfc4122(),
        );
    }
}

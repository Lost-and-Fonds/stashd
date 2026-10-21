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

/**
 * Provide the canonical OCFL storage boundary for the configured Vault.
 *
 * Local Object writes share non-expiring publication authority and per-Object
 * locks, retaining committed history. The transaction service reuses this authority.
 * Publication inspection distinguishes acknowledged history from stranded
 * files; it does not repair them or provide a multi-Object transaction.
 */
#[Singleton]
class Vault
{
    private const string IDENTITY_FILENAME = 'identity.json';
    private const string VAULT_FORMAT = 'stashd-vault';
    private const int VAULT_VERSION = 1;

    private ?VaultIdentity $identity = null;
    private bool $publicationHeld = false;
    private readonly ObjectWriteLock $writeLock;

    /**
     * Bind the tagged archival storage and create the local Object lock.
     *
     * Construction performs no storage initialization or identity read.
     */
    public function __construct(
        #[Tag('vault')]
        private readonly ArchiveStorage $storage,
    ) {
        $this->writeLock = new ObjectWriteLock();
    }

    /**
     * Load the persisted Vault identity and open its OCFL storage root.
     *
     * Refreshes the cached identity on each call. A missing identity raises an
     * Exception; malformed identity data and OCFL failures propagate to callers.
     */
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

    /**
     * Initialize a new OCFL root and persist a newly generated Vault identity.
     *
     * Use only for a new, empty root. OCFL rejects an existing nonempty root;
     * root creation and identity writing are separate filesystem operations.
     */
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

    /**
     * Commit the first version of a typed Object under its write lock.
     *
     * An uncommitted shell may be retried, but committed history is rejected.
     * Validation runs before locking; publication failures can leave material
     * that must be inspected before another write.
     *
     * @param array<string, string|array{contents: string}> $files
     * @throws OcflException When committed history already exists or OCFL publication fails.
     */
    public function createObject(IdentifierType $type, ?Uuid $uuid = null, array $files = []): OcflObject
    {
        $this->validateFiles($files);
        $id = $this->urnFromUuid($type, $uuid);

        return $this->exclusivePublication(fn (): OcflObject => $this->writeLock->exclusiveWrite($id, function () use ($id, $files): OcflObject {
            $root = $this->open();
            $object = $root->hasObject($id->toString())
                ? $this->objectForWrite($id)
                : $root->createObject(id: $id->toString());

            if ($object->id() !== $id->toString() || $object->head() !== '') {
                throw new OcflException(ErrorCode::E001, "Object '{$id}' already has committed history.");
            }

            $version = $object->newVersion();

            return $this->stageFiles($version, $files)->commit();
        }));
    }

    /**
     * Append file changes to an existing Object while holding its write lock.
     *
     * Paths omitted from the change set remain in the new version. Earlier
     * versions are retained. Reconciliation issues block publication; a failed
     * commit may leave physical material requiring inspection.
     *
     * @param array<string, string|array{contents: string}> $files
     */
    public function updateObject(Urn $objectUrn, array $files): OcflObject
    {
        $this->validateFiles($files);

        return $this->exclusivePublication(fn (): OcflObject => $this->writeLock->exclusiveWrite($objectUrn, function () use ($objectUrn, $files): OcflObject {
            $version = $this->objectForWrite($objectUrn)->newVersion();

            return $this->stageFiles($version, $files)->commit();
        }));
    }

    /**
     * Publish a complete logical snapshot while holding the Object write lock.
     *
     * The validator receives the existing Object, or null for a new identity,
     * before a version is staged. Omitted paths are removed from the new logical
     * state while remaining available in earlier versions. Validation and
     * publication share one Object lock, not a multi-Object transaction.
     *
     * @param array<string, string|array{contents: string}> $files
     * @param callable(?OcflObject): void $validate
     */
    public function commitSnapshot(Urn $id, array $files, callable $validate): OcflObject
    {
        $this->validateFiles($files);

        return $this->exclusivePublication(fn (): OcflObject => $this->writeLock->exclusiveWrite($id, function () use ($id, $files, $validate): OcflObject {
            $root = $this->open();
            $object = $root->hasObject($id->toString()) ? $this->objectForWrite($id) : null;
            $validate($object);
            $object ??= $root->createObject($id->toString());
            $version = $object->newVersion();
            if ($object->head() !== '') {
                foreach ($object->logicalPaths($object->head()) as $path) {
                    $version->removeFile($path);
                }
            }

            return $this->stageFiles($version, $files)->commit();
        }));
    }

    /**
     * Resolve the retained local transaction directory inside the initialized Vault.
     *
     * Remote providers cannot provide this phase's filesystem authority guarantees.
     */
    public function transactionDirectory(): string
    {
        if (! $this->storage instanceof \App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage) {
            throw new RuntimeException('Preservation transactions require local archival storage.');
        }
        $directory = $this->storage->rootPath() . '/extensions/stashd-transactions';
        if (is_link($directory) || is_link(dirname($directory))) {
            throw new RuntimeException('Transaction evidence may not traverse symbolic links.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create transaction evidence directory.');
        }
        return $directory;
    }

    /**
     * Hold non-expiring local authority across publication and verification.
     *
     * Nested writes on this instance reuse authority. The callback observes a
     * denied nonblocking claim before waiting, and acquisition before any write.
     * The kernel releases authority on process death; live owners cannot expire.
     *
     * @template T
     * @param callable(): T $operation
     * @param null|callable(string): void $boundary
     * @return T
     */
    public function exclusivePublication(callable $operation, ?callable $boundary = null): mixed
    {
        if ($this->publicationHeld) {
            return $operation();
        }
        $path = $this->transactionDirectory() . '/authority.lock';
        if (is_link($path)) {
            throw new RuntimeException('Publication authority may not be a symbolic link.');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Could not open publication authority.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                if ($boundary !== null) {
                    $boundary('authority-contended');
                }
                if (! flock($lock, LOCK_EX)) {
                    throw new RuntimeException('Could not claim publication authority.');
                }
            }
            $this->publicationHeld = true;
            if ($boundary !== null) {
                $boundary('authority-held');
            }
            return $operation();
        } finally {
            $this->publicationHeld = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Inspect acknowledged history and stranded material under the publication lock.
     *
     * Returns diagnostic issues without repairing, promoting or deleting files.
     * Content hashing is enabled by default. Disabling it still verifies inventory
     * checksums/history and detects stranded material, for a short locked recheck
     * after content was independently verified outside global authority.
     *
     * @return array{head: ?string, versions: list<string>, issues: list<string>}
     */
    public function inspectObject(Urn $id, bool $verifyContent = true): array
    {
        return $this->writeLock->exclusiveWrite($id, fn (): array => $this->publication($id, $verifyContent)->result());
    }

    /**
     * Open the history acknowledged by the root inventory checksum.
     *
     * Readers may receive verified older history even when inspection reports
     * stranded publication material. This path checks inventory history but
     * skips content hashing; consumers must verify the payloads they use.
     */
    public function getObject(Urn $id): OcflObject
    {
        $state = $this->publication($id, verifyContent: false);
        if ($state->object !== null) {
            return $state->object;
        }
        if ($state->issues === []) {
            return $this->open()->getObject($id->toString());
        }
        throw new OcflException(ErrorCode::E060, implode('; ', $state->issues));
    }

    /**
     * Resolve the Object layout path and inspect its publication evidence.
     *
     * Content verification is enabled by default; disabling it still checks
     * acknowledged inventories and historical consistency.
     */
    private function publication(Urn $id, bool $verifyContent = true): PublicationInspection
    {
        $root = $this->open();
        $path = '/' . $root->layout()->resolveObjectPath($id->toString());
        return new PublicationInspection($this->storage, $path, $id->toString(), $verifyContent);
    }

    /**
     * Require reconciled publication evidence before extending an Object.
     *
     * Unlike the read path, this rejects any inspection issue with an
     * UnexpectedValueException. Callers must already hold the Object lock.
     */
    private function objectForWrite(Urn $id): OcflObject
    {
        $state = $this->publication($id);
        if ($state->issues !== []) {
            throw new \UnexpectedValueException('Publication requires reconciliation: ' . implode('; ', $state->issues));
        }
        return $state->object ?? $this->open()->getObject($id->toString());
    }

    /**
     * Reject empty changes and unsafe logical paths before staging files.
     *
     * Sources must be path strings or inline contents entries. Absolute source
     * paths must name readable regular files; this does not snapshot their bytes.
     *
     * @param array<array-key, mixed> $files
     */
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

    /**
     * Add path sources and inline bytes to an uncommitted version builder.
     *
     * The caller owns locking and commit. This helper does not publish a version.
     *
     * @param array<string, string|array{contents: string}> $files
     */
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

    /**
     * Read one exact historical file into memory and close its stream.
     *
     * Missing Objects, versions or paths propagate their read failures. Use the
     * stream variant when the complete payload should not be buffered.
     */
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
        $object = $this->getObject($objectUrn);
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

    /**
     * Return the last identity loaded or created by this Vault instance.
     *
     * Returns null until an identity is assigned in memory. Creation assigns
     * it before writing identity.json, so a value alone does not prove successful
     * initialization or current storage availability.
     */
    public function getIdentity(): ?VaultIdentity
    {
        return $this->identity;
    }

    /**
     * Build a Stashd URN in the requested identity namespace.
     *
     * A supplied UUID gives reproducible identity; omitting it generates a
     * random UUID. No Object is created or checked for existence.
     */
    public function urnFromUuid(IdentifierType $type, ?Uuid $uuid = null): Urn
    {
        $uuid ??= Uuid::v4();

        return Urn::fromRfc2141(
            nid: 'stashd',
            nss: $type->value . ':' . $uuid->toRfc4122(),
        );
    }
}

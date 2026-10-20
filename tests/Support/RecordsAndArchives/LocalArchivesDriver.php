<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

use App\RecordsAndArchives\Storage\ArchiveStorageInitializer;
use App\RecordsAndArchives\Vault;
use Exception;
use InvalidArgumentException;
use LogicException;
use Ottosmops\Ocfl\Validation\ErrorCode;
use Ottosmops\Ocfl\Validation\OcflException;
use PHPUnit\Framework\IncompleteTestError;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Clock\ClockInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\StorageInitializer;

/**
 * Runs the Records & Archives acceptance contract against real local services.
 *
 * Each operation gets a fresh Tempest container and the fixture's private
 * filesystem root. Unsupported operations remain INCOMPLETE until Stashd
 * implements the corresponding application capability.
 */
final class LocalArchivesDriver implements ArchivesDriver
{
    /**
     * Execute an implemented operation without substituting mock results.
     *
     * The fresh container means reopening a Vault must read its real files
     * rather than returning the same in-memory singleton.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $operation, array $arguments): array
    {
        if (! in_array(
            $operation,
            [
                'vault.initialize',
                'vault.open',
                'vault.list_objects',
                'storage.ocfl_round_trip',
                'vault.object_create',
                'vault.object_update',
                'vault.object_read',
                'vault.object_stream_read',
                'item.record',
                'item.read',
            ],
            true,
        )) {
            throw new IncompleteTestError("Archives operation '{$operation}' is not implemented by the local driver.");
        }

        $primary = self::validatePrimary($arguments);

        $previous = GenericContainer::instance();
        $container = new GenericContainer();

        $container->config(new LocalStorageConfig(path: $primary, tag: 'vault'));
        $container->addInitializer(StorageInitializer::class);
        $container->addInitializer(ArchiveStorageInitializer::class);
        $container->addInitializer(ClockInitializer::class);

        GenericContainer::setInstance($container);

        try {
            $vault = $container->get(Vault::class);

            return match ($operation) {
                'vault.initialize' => self::initialize($vault),
                'vault.open' => self::open($vault),
                'vault.list_objects' => ['object_ids' => $vault->open()->listObjects()],
                'storage.ocfl_round_trip' => self::ocflRoundTrip($vault),
                'vault.object_create' => self::createObject($vault, $arguments),
                'vault.object_update' => self::updateObject($vault, $arguments),
                'vault.object_read' => self::readObject($vault, $arguments),
                'vault.object_stream_read' => self::readObjectStream($vault, $arguments),
                'item.record' => self::recordItem($vault, $arguments),
                'item.read' => self::readItem($vault, $arguments),
            };
        } finally {
            GenericContainer::setInstance($previous);
        }
    }

    /**
     * Create a real OCFL storage root and return its persisted identity.
     *
     * Only the known OCFL rejection for a nonempty root is translated into
     * an expected rejection; unrelated I/O errors must fail the test.
     *
     * @return array{vault_id: string}
     */
    private static function initialize(Vault $vault): array
    {
        try {
            $vault->create();
        } catch (OcflException $exception) {
            if ($exception->errorCode === ErrorCode::E001) {
                throw new ArchivesRejected('Vault initialization was rejected.', previous: $exception);
            }

            throw $exception;
        }

        return ['vault_id' => self::identityId($vault)];
    }

    /**
     * Open the Vault afresh and report its on-disk identity.
     *
     * @return array{vault_id: string}
     */
    private static function open(Vault $vault): array
    {
        try {
            $vault->open();
        } catch (Exception $exception) {
            if ($exception::class === Exception::class && $exception->getMessage() === 'Vault identity does not exist') {
                throw new ArchivesRejected('Vault has not been initialized.', previous: $exception);
            }

            throw $exception;
        }

        return ['vault_id' => self::identityId($vault)];
    }

    /**
     * Exercise real OCFL version publication through Stashd's storage adapter.
     *
     * This is a storage-integration contract, not a substitute for the
     * application-level Asset preservation tests.
     *
     * @return array{object_id: string, versions: list<string>, listed_files: list<string>}
     */
    private static function ocflRoundTrip(Vault $vault): array
    {
        $root = $vault->open();
        $id = 'urn:uuid:' . Uuid::v4()->toRfc4122();

        $object = $root->createObject($id);

        $first = $object
            ->newVersion()
            ->addContents('example.txt', 'original bytes')
            ->commit();

        $second = $root
            ->getObject($id)
            ->newVersion()
            ->addContents('example.txt', 'revised bytes')
            ->commit();

        return [
            'object_id' => $id,
            'versions' => [$first->head(), $second->head()],
            'listed_files' => $second->fs->listFilesRecursively($second->path),
        ];
    }

    /** @param array<string, mixed> $arguments @return array{object_id: string, version: string} */
    private static function createObject(Vault $vault, array $arguments): array
    {
        $type = \App\RecordsAndArchives\IdentifierType::from($arguments['type']);
        $uuid = Uuid::fromString($arguments['uuid']);
        $object = $vault->createObject($type, $uuid, $arguments['files']);

        return ['object_id' => $object->id(), 'version' => $object->head()];
    }

    /** @param array<string, mixed> $arguments @return array{object_id: string, version: string} */
    private static function updateObject(Vault $vault, array $arguments): array
    {
        $object = $vault->updateObject(\League\Uri\Urn::fromString($arguments['object_id']), $arguments['files']);

        return ['object_id' => $object->id(), 'version' => $object->head()];
    }

    /** @param array<string, mixed> $arguments @return array{bytes: string} */
    private static function readObject(Vault $vault, array $arguments): array
    {
        return ['bytes' => $vault->readObjectContent(
            \League\Uri\Urn::fromString($arguments['object_id']),
            $arguments['version'],
            $arguments['logical_path'],
        )];
    }

    /** @param array<string, mixed> $arguments @return array{stream: resource} */
    private static function readObjectStream(Vault $vault, array $arguments): array
    {
        return ['stream' => $vault->readObjectStream(
            \League\Uri\Urn::fromString($arguments['object_id']),
            $arguments['version'],
            $arguments['logical_path'],
        )];
    }

    /** @param array<string, mixed> $arguments @return array{object_id: string, version: string} */
    private static function recordItem(Vault $vault, array $arguments): array
    {
        try {
            $uri = \League\Uri\Uri::new($arguments['uri']);
        } catch (\Throwable $exception) {
            throw new ArchivesRejected('Item identity must be a valid absolute URI.', previous: $exception);
        }
        if ($uri->getScheme() === null) {
            throw new ArchivesRejected('Item identity must be a valid absolute URI.');
        }
        $canonicalUri = $uri->normalize()->toString();
        $namespace = Uuid::fromString('6ba7b811-9dad-11d1-80b4-00c04fd430c8');
        $itemNamespace = Uuid::v5($namespace, 'urn:stashd:item-identity');
        $itemUuid = Uuid::v5($itemNamespace, $canonicalUri);
        $objectUrn = $vault->urnFromUuid(\App\RecordsAndArchives\IdentifierType::Item, $itemUuid);
        $pins = $arguments['pins'] ?? [];
        if (! is_array($pins)) {
            throw new ArchivesRejected('Item pins must be a list of exact Asset versions.');
        }
        foreach ($pins as $pin) {
            if (! is_array($pin) || ! is_string($pin['object_id'] ?? null) || ! is_string($pin['version'] ?? null)) {
                throw new ArchivesRejected('Item pins must identify an Asset Object and version.');
            }
            $assetId = $pin['object_id'];
            if (! str_starts_with($assetId, 'urn:stashd:asset:')) {
                throw new ArchivesRejected('Item pin must identify an Asset Object.');
            }
            try {
                $assetUrn = \League\Uri\Urn::fromString($assetId);
                $asset = $vault->getObject($assetUrn);
                if (! in_array($pin['version'], $asset->versionNames(), true)) {
                    throw new ArchivesRejected('Item pin does not identify an existing Asset version.');
                }
                $assetRecord = json_decode($vault->readObjectContent($assetUrn, $pin['version'], 'asset.json'), true, flags: JSON_THROW_ON_ERROR);
            } catch (ArchivesRejected $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new ArchivesRejected('Item pin does not identify an existing Asset version.', previous: $exception);
            }
            if (($assetRecord['item_uri'] ?? null) !== $canonicalUri) {
                throw new ArchivesRejected('Item pin references an Asset owned by another Item.');
            }
        }
        $files = ['record.json' => ['contents' => json_encode([
            'uri' => $canonicalUri,
            'source_uri' => $arguments['uri'],
            'pins' => $pins,
            'metadata' => $arguments['metadata'] ?? [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]];
        $root = $vault->open();
        if ($root->hasObject($objectUrn->toString())) {
            $object = $vault->updateObject($objectUrn, $files);
        } else {
            $object = $vault->createObject(\App\RecordsAndArchives\IdentifierType::Item, $itemUuid, $files);
        }

        return ['object_id' => $object->id(), 'version' => $object->head()];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private static function readItem(Vault $vault, array $arguments): array
    {
        $objectUrn = \League\Uri\Urn::fromString($arguments['object_id']);
        $object = $vault->getObject($objectUrn);
        $version = $arguments['version'] ?? $object->head();
        $record = json_decode($vault->readObjectContent($objectUrn, $version, 'record.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($record)) {
            throw new RuntimeException('Archived Item record is malformed.');
        }

        return $record;
    }

    private static function identityId(Vault $vault): string
    {
        return $vault->getIdentity()?->identifier->toString() ?? throw new LogicException('Vault did not establish an identity.');
    }

    /**
     * Reject paths outside the disposable fixture before touching storage.
     *
     * Symlink components are disallowed so a test cannot accidentally write
     * into a different directory through its temporary workspace.
     *
     * @param array<string, mixed> $arguments
     */
    private static function validatePrimary(array $arguments): string
    {
        $workspace = $arguments['workspace'] ?? null;
        $primary = $arguments['primary'] ?? null;

        if (! is_string($workspace) || ! is_dir($workspace) || ! is_string($primary)) {
            throw new InvalidArgumentException('A disposable workspace and primary root are required.');
        }

        $workspace = rtrim($workspace, '/');

        if (! str_starts_with($primary, $workspace . '/')) {
            throw new InvalidArgumentException('Primary root must be inside the acceptance workspace.');
        }

        $relative = substr($primary, strlen($workspace) + 1);
        $cursor = $workspace;

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Primary root contains an invalid path component.');
            }

            $cursor .= '/' . $segment;

            if (is_link($cursor)) {
                throw new InvalidArgumentException('Primary root may not contain symbolic links.');
            }
        }

        return $primary;
    }
}

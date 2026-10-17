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
            ['vault.initialize', 'vault.open', 'vault.list_objects', 'storage.ocfl_round_trip', 'vault.object_create', 'vault.object_update', 'vault.object_read', 'vault.object_stream_read'],
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

    /**
     * Return the identity loaded or created by the actual Vault service.
     */
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

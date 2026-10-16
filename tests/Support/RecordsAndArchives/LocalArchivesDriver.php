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
        if (!in_array($operation, ['vault.initialize', 'vault.open', 'vault.list_objects'], true)) {
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
     * Return the identity loaded or created by the actual Vault service.
     */
    private static function identityId(Vault $vault): string
    {
        return $vault->getIdentity()?->identifier->toString()
            ?? throw new LogicException('Vault did not establish an identity.');
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

        if (!is_string($workspace) || !is_dir($workspace) || !is_string($primary)) {
            throw new InvalidArgumentException('A disposable workspace and primary root are required.');
        }

        $workspace = rtrim($workspace, '/');

        if (!str_starts_with($primary, $workspace . '/')) {
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

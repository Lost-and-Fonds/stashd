<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\AcquiredFile;
use RuntimeException;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;

use function Tempest\Support\Filesystem\create_temporary_directory;

/**
 * Own one invocation's private byte copy and its unforgeable in-process receipt.
 *
 * The coordinator must close staging on every normal exit. Sealed transaction
 * custody, not this temporary directory, supports recovery after process death.
 */
final class AcquisitionStaging
{
    private string $directory;
    private GenericStorage $storage;
    private ?AcquiredFile $accepted = null;

    /**
     * Create a private temporary directory without touching canonical Vault bytes.
     */
    public function __construct()
    {
        $this->directory = create_temporary_directory('stashd-acquire-');
        chmod($this->directory, 0o700);
        $this->storage = new GenericStorage(new LocalStorageConfig(path: $this->directory));
    }

    /**
     * Stream one source into host custody and compute its accepted size and digest.
     *
     * The caller owns and closes the source stream, checks EOF and source stability,
     * and must discard staging if copying or verification throws.
     *
     * @param resource $stream
     */
    public function capture(mixed $stream, string $relative, string $root): AcquiredFile
    {
        if (! is_resource($stream) || $this->accepted !== null) {
            throw new RuntimeException('Acquisition requires one open source stream.');
        }

        $this->storage->writeStream('payload', $stream);
        $size = $this->storage->fileSize('payload');
        $digest = hash_file('sha512', $this->directory . '/payload');
        if ($digest === false) {
            throw new RuntimeException('Could not verify staged acquisition bytes.');
        }

        return $this->accepted = new AcquiredFile(bin2hex(random_bytes(24)), $size, $digest, $relative, $root);
    }

    /**
     * Resolve only the receipt created by this invocation for transaction adoption.
     *
     * A receipt from another invocation is rejected before exposing any path.
     */
    public function path(AcquiredFile $receipt): string
    {
        if ($receipt !== $this->accepted) {
            throw new RuntimeException('Acquisition receipt does not belong to this invocation.');
        }

        return $this->directory . '/payload';
    }

    /**
     * Delete this invocation's staging without deleting transaction custody or history.
     */
    public function close(): void
    {
        $this->storage->deleteDirectory();
    }
}

<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Config\StashdConfig;
use App\System\Storage\FilesystemProbe;
use Tempest\Support\Filesystem;
use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

/** Hardlink-first publisher — never silently copies. */
final readonly class HardlinkPublisher
{
    public function __construct(
        private StashdConfig $config,
        private FilesystemProbe $probe,
    ) {}

    public static function sameFile(string $pathA, string $pathB): bool
    {
        clearstatcache(true, $pathA);
        clearstatcache(true, $pathB);

        if (! is_file($pathA) || ! is_file($pathB)) {
            return false;
        }

        $inodeA = @fileinode($pathA);
        $inodeB = @fileinode($pathB);

        if (! is_int($inodeA) || ! is_int($inodeB)) {
            return false;
        }

        if ($inodeA !== $inodeB) {
            return false;
        }

        $statA = @stat($pathA);
        $statB = @stat($pathB);

        if (
            is_array($statA)
            && is_array($statB)
            && isset($statA['dev'], $statB['dev'])
            && $statA['dev'] !== $statB['dev']
        ) {
            return false;
        }

        return true;
    }

    public function assertHardlinksAvailable(string $targetRoot): void
    {
        if (getenv('STASHD_BROADCAST_HARDLINK_FORCE_FAIL') === '1') {
            throw BroadcastException::withCode(
                'broadcast_hardlink_unavailable',
                'Hardlinks are unavailable for broadcast publishing.',
            );
        }

        $result = $this->probe->probeHardlinkCrossRoot($this->config->vaultPath(), $targetRoot);

        if (! $result->ok) {
            throw BroadcastException::withCode(
                'broadcast_hardlink_unavailable',
                $result->message,
            );
        }
    }

    public function publishHardlink(string $sourcePath, string $targetPath, string $targetRoot): void
    {
        $this->assertHardlinksAvailable($targetRoot);

        if (! is_file($sourcePath)) {
            throw BroadcastException::withCode(
                'broadcast_source_missing',
                'Vault source file is missing.',
            );
        }

        $directory = dirname($targetPath);

        try {
            Filesystem\create_directory($directory, 0o775);
        } catch (FilesystemException) {
            throw BroadcastException::withCode(
                'broadcast_publish_failed',
                'Could not create broadcast directory.',
            );
        }

        if (is_file($targetPath)) {
            if (self::sameFile($sourcePath, $targetPath)) {
                return;
            }

            if (! unlink($targetPath)) {
                throw BroadcastException::withCode(
                    'broadcast_publish_failed',
                    'Existing broadcast file is not a hardlink and could not be replaced.',
                );
            }
        }

        clearstatcache(true, $sourcePath);
        clearstatcache(true, $targetPath);

        if (! @link($sourcePath, $targetPath)) {
            $error = error_get_last()['message'] ?? 'link() returned false';

            throw BroadcastException::withCode(
                'broadcast_hardlink_unavailable',
                'Hardlink creation failed: ' . $error,
            );
        }

        if (! self::sameFile($sourcePath, $targetPath)) {
            @unlink($targetPath);

            throw BroadcastException::withCode(
                'broadcast_hardlink_unavailable',
                'Hardlink was created but inode verification failed.',
            );
        }
    }

    public function verifyHardlink(string $sourcePath, string $targetPath): bool
    {
        if (! is_file($targetPath)) {
            return false;
        }

        if (! is_file($sourcePath)) {
            return false;
        }

        return self::sameFile($sourcePath, $targetPath);
    }
}

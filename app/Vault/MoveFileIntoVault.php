<?php

declare(strict_types=1);

namespace App\Vault;

use RuntimeException;
use Tempest\Support\Filesystem;
use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

/** Moves staged files into Vault using rename when possible, copy+fsync otherwise. */
final class MoveFileIntoVault
{
    public function moveIntoPlace(string $source, string $destination): void
    {
        $directory = dirname($destination);

        try {
            Filesystem\create_directory($directory, 0o775);
        } catch (FilesystemException) {
            throw new RuntimeException("Unable to create Vault directory: {$directory}");
        }

        if (file_exists($destination)) {
            throw new RuntimeException("Refusing to overwrite existing Vault file: {$destination}");
        }

        if (@rename($source, $destination)) {
            return;
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("Unable to copy staged file into Vault: {$destination}");
        }

        $handle = fopen($destination, 'rb');

        if ($handle !== false) {
            fflush($handle);

            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
        }

        if (! unlink($source)) {
            throw new RuntimeException("Vault file copied but staged temp file could not be removed: {$source}");
        }
    }
}

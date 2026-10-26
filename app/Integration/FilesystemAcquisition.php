<?php

declare(strict_types=1);

namespace App\Integration;

use App\Administration\VaultConfig;
use App\Integration\DTO\AcquiredFile;
use App\Integration\DTO\DiscoveredItem;
use App\RecordsAndArchives\AssetArchive;
use RuntimeException;

final readonly class FilesystemAcquisition implements InputAcquisition
{
    public function __construct(
        private FilesystemRoots $roots,
        private IntegrationConfigurations $configurations,
        private VaultConfig $vault,
    ) {}

    public function acquire(string $inputId, array $options, DiscoveredItem $item, AcquisitionStaging $staging): AcquiredFile
    {
        $reference = json_decode($item->reference, true, flags: JSON_THROW_ON_ERROR);
        $configured = $options['root'] ?? null;
        if (
            ! is_string($configured)
            || ! is_array($reference)
            || ($reference['version'] ?? null) !== 1
            || ($reference['root'] ?? null) !== $configured
            || ! is_string($reference['path'] ?? null)
            || $this->vault->provider !== 'local'
        ) {
            throw new RuntimeException('Discovery reference no longer matches the Filesystem configuration.');
        }

        $relative = $reference['path'];
        $this->roots->relative($relative);
        if ($item->id !== hash('sha256', $relative)) {
            throw new RuntimeException('Discovery source identity does not match its path.');
        }

        $root = $this->roots->canonical($configured);
        $vault = $this->roots->canonical($this->vault->root);
        $this->roots->requireSeparate($root, $vault);
        foreach ($this->configurations->all() as $configuration) {
            if (! ($configuration['id'] !== $inputId && $configuration['component'] === 'filesystem')) {
                continue;
            }

            $other = $configuration['settings']['root'] ?? null;
            if (! is_string($other)) {
                throw new RuntimeException('Stored Filesystem configuration has no root.');
            }

            $other = $this->roots->canonical($other, allowMissing: true);
            $this->roots->requireSeparate($root, $other);
            $this->roots->requireSeparate($vault, $other);
        }

        $path = $root . '/' . $relative;
        AssetArchive::validateSource($path);
        $before = lstat($path);
        $stream = fopen($path, 'rb');
        if ($before === false || $stream === false) {
            throw new RuntimeException('Could not open acquisition source.');
        }

        try {
            if ($before !== fstat($stream)) {
                throw new RuntimeException('Source changed while opening acquisition.');
            }

            $receipt = $staging->capture($stream, $relative, $root);
            if (fread($stream, 1) !== '' || ! feof($stream) || $receipt->size_bytes !== $before['size'] || ! rewind($stream)) {
                throw new RuntimeException('Source acquisition did not read the complete regular file.');
            }

            $hash = hash_init('sha512');
            $size = hash_update_stream($hash, $stream);
            $digest = hash_final($hash);
            clearstatcache(true, $path);
            AssetArchive::validateSource($path);
            $keys = array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']);
            $opened = fstat($stream);
            $after = lstat($path);
            if (
                $opened === false
                || $after === false
                || array_intersect_key($before, $keys) !== array_intersect_key($opened, $keys)
                || array_intersect_key($before, $keys) !== array_intersect_key($after, $keys)
                || ! feof($stream)
                || $size !== $receipt->size_bytes
                || $digest !== $receipt->digest
            ) {
                throw new RuntimeException('Source changed during acquisition; retry with a stable file.');
            }

            return $receipt;
        } finally {
            fclose($stream);
        }
    }
}

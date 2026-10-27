<?php

declare(strict_types=1);

namespace App\Integration;

use App\Administration\VaultConfig;
use App\Integration\DTO\PublishedFile;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Reconstruct selected historical files using only verified invocation streams.
 *
 * Publication is copy-only and atomic per file. Operators must exclude external
 * writers: the final checked rename is not an atomic no-replace primitive.
 *
 * @phpstan-type DeliveredAsset array{id: string, reference: string, size_bytes: int, media_type: null, metadata: array}
 * @phpstan-type DeliveredItem array{id: string, assets: list<DeliveredAsset>, metadata: list<array{schema: string, json: string}>}
 * @phpstan-type PlannedFile array{path: string, item: string, asset: DeliveredAsset}
 */
final readonly class FilesystemPublisher implements BroadcastPublisher
{
    /**
     * Reuse the local path policy and saved roots without accessing source bytes.
     */
    public function __construct(
        private FilesystemRoots $roots,
        private IntegrationConfigurations $configurations,
        private VaultConfig $vault,
    ) {}

    /**
     * Preflight the entire selection on disk, then copy and report each complete file.
     *
     * The private destination-local workspace bounds memory and owns all temporary
     * files. Normal failure removes only that workspace; abrupt death can leave it.
     */
    public function publish(BroadcastInvocation $invocation, array $settings): FileReportStatus
    {
        $configured = $settings['root'] ?? null;
        if (! is_string($configured)) {
            throw new RuntimeException('Filesystem Broadcast requires a configured root.');
        }

        $root = $this->checkRoot($configured);
        $this->directory($root);
        $workspace = $root . '/.stashd-publication-' . bin2hex(random_bytes(16));
        if (! mkdir($workspace, 0o700)) {
            throw new RuntimeException('Could not create private publication workspace.');
        }

        $plan = null;
        try {
            $plan = fopen($workspace . '/plan', 'x+b');
            if ($plan === false) {
                throw new RuntimeException('Could not open publication plan.');
            }

            while (($batch = $invocation->next(100)) !== null) {
                foreach ($batch as $item) {
                    $path = $this->sourcePath($item);
                    $this->reserve($workspace, $path);
                    $this->target($root, $path);
                    $record = json_encode(['path' => $path, 'item' => $item['id'], 'asset' => $item['assets'][0]], JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($plan, $record) !== strlen($record)) {
                        throw new RuntimeException('Could not save publication plan.');
                    }
                }
            }

            rewind($plan);
            while (($line = fgets($plan)) !== false) {
                /** @var PlannedFile $record */
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->checkRoot($configured);
                $this->copy($invocation, $root, $workspace, $record['path'], $record['asset']);
                $invocation->reportFiles(new PublishedFile($record['path'], $record['item'], $record['asset']['id']));
            }

            if (! feof($plan)) {
                throw new RuntimeException('Could not read complete publication plan.');
            }

            return FileReportStatus::Complete;
        } finally {
            if (is_resource($plan)) {
                fclose($plan);
            }

            $this->cleanup($workspace);
        }
    }

    /**
     * Remove only the private invocation workspace on normal return or failure.
     */
    private function cleanup(string $workspace): void
    {
        if (is_link($workspace) || ! is_dir($workspace)) {
            return;
        }

        foreach (new \DirectoryIterator($workspace) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($workspace);
    }

    /**
     * Require one preserved Asset and unambiguous canonical Filesystem provenance.
     *
     * @param DeliveredItem $item
     */
    private function sourcePath(array $item): string
    {
        $source = null;
        foreach ($item['metadata'] as $facet) {
            if ($facet['schema'] !== 'stashd.filesystem.source') {
                continue;
            }

            if ($source !== null) {
                throw new RuntimeException('Contradictory Filesystem source provenance.');
            }

            $source = json_decode($facet['json'], true, flags: JSON_THROW_ON_ERROR);
        }

        if (
            ! is_array($source)
            || ($source['version'] ?? null) !== 1
            || ! is_string($source['input_id'] ?? null)
            || ! Uuid::isValid($source['input_id'])
            || ! is_string($source['root'] ?? null)
            || ! str_starts_with($source['root'], '/')
            || ! is_string($source['path'] ?? null)
            || ($source['outcome'] ?? null) !== 'complete'
            || count($item['assets']) !== 1
        ) {
            throw new RuntimeException('Missing or invalid Filesystem source provenance or original Asset.');
        }

        $this->roots->relative($source['path']);
        $this->roots->relative(substr(rtrim($source['root'], '/'), 1));

        return $source['path'];
    }

    /**
     * Reject changed roots and overlaps while allowing an offline Input directory.
     */
    private function checkRoot(string $configured): string
    {
        if ($this->vault->provider !== 'local') {
            throw new RuntimeException('Filesystem publication requires a local Vault.');
        }

        $root = $this->roots->canonical($configured, allowMissing: true);
        $vault = $this->roots->canonical($this->vault->root);
        $this->roots->requireSeparate($root, $vault);
        foreach ($this->configurations->all() as $configuration) {
            if ($configuration['component'] !== 'filesystem') {
                continue;
            }

            $other = $configuration['settings']['root'] ?? null;
            if (! is_string($other)) {
                throw new RuntimeException('Stored Filesystem configuration has no root.');
            }

            $other = $this->roots->canonical($other, allowMissing: true);
            $this->roots->requireSeparate($other, $vault);
            if ($other !== $root || $configuration['kind'] !== 'broadcast') {
                $this->roots->requireSeparate($root, $other);
            }
        }

        return $root;
    }

    /**
     * Index case-folded prefixes on disk so duplicate and file/directory conflicts fail before copying.
     */
    private function reserve(string $workspace, string $path): void
    {
        $prefix = '';
        foreach (explode('/', $path) as $part) {
            $prefix .= ($prefix === '' ? '' : '/') . $part;
            $file = $prefix === $path;
            $marker = $workspace . '/name-' . hash('sha256', mb_convert_case($prefix, MB_CASE_FOLD, 'UTF-8'));
            $record = json_encode(['path' => $prefix, 'file' => $file], JSON_THROW_ON_ERROR);
            if (file_exists($marker)) {
                $contents = file_get_contents($marker);
                if ($contents === false) {
                    throw new RuntimeException('Could not read destination collision index.');
                }

                /** @var array{path: string, file: bool} $previous */
                $previous = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                if ($previous['path'] !== $prefix || $previous['file'] || $file) {
                    throw new RuntimeException('Duplicate, case-folding or file/directory destination collision: ' . $path);
                }
            } elseif (file_put_contents($marker, $record) !== strlen($record)) {
                throw new RuntimeException('Could not index publication destinations.');
            }
        }
    }

    /**
     * Check every existing component and reject symlinks, special entries and case aliases.
     */
    private function target(string $root, string $relative): string
    {
        $this->roots->canonical($root);
        $current = $root;
        $parts = explode('/', $relative);
        foreach ($parts as $position => $part) {
            if (is_dir($current)) {
                foreach (new \DirectoryIterator($current) as $entry) {
                    $name = $entry->getFilename();
                    if ($name !== $part && mb_convert_case($name, MB_CASE_FOLD, 'UTF-8') === mb_convert_case($part, MB_CASE_FOLD, 'UTF-8')) {
                        throw new RuntimeException('Existing case-folding destination collision: ' . $relative);
                    }
                }
            }

            $current .= '/' . $part;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false) {
                continue;
            }

            $expected = $position === (count($parts) - 1) ? 0o100_000 : 0o040_000;
            if (($stat['mode'] & 0o170_000) !== $expected) {
                throw new RuntimeException('Unsafe or incompatible destination path: ' . $relative);
            }
        }

        return $current;
    }

    /**
     * Create directories one component at a time, rechecking that no link is traversed.
     */
    private function directory(string $path): void
    {
        $this->roots->canonical($path, allowMissing: true);
        if (is_dir($path)) {
            return;
        }

        $this->directory(dirname($path));
        if (! mkdir($path, 0o755) && ! is_dir($path)) {
            throw new RuntimeException('Could not create publication directory.');
        }

        $this->roots->canonical($path);
    }

    /**
     * Hash verified Vault bytes, verify private staging, then skip matching files or rename.
     *
     * Every opened reader and writer is closed on failure. Differing existing bytes
     * are never intentionally replaced; the operator must prevent external races.
     *
     * @param DeliveredAsset $asset
     */
    private function copy(BroadcastInvocation $invocation, string $root, string $workspace, string $relative, array $asset): void
    {
        $destination = $this->target($root, $relative);
        $this->directory(dirname($destination));
        $this->roots->canonical($workspace);
        $workspaceStat = stat($workspace);
        $parentStat = stat(dirname($destination));
        if ($workspaceStat === false || $parentStat === false || $workspaceStat['dev'] !== $parentStat['dev']) {
            throw new RuntimeException('Publication staging and destination must be on the same filesystem.');
        }

        $temporary = $workspace . '/bytes';
        $writer = fopen($temporary, 'x+b');
        if ($writer === false) {
            throw new RuntimeException('Could not create staged publication file.');
        }

        $reader = null;
        try {
            $reader = $invocation->openAsset($asset['reference']);
            $hash = hash_init('sha512');
            $size = 0;
            while (($bytes = $reader->read()) !== null) {
                if (fwrite($writer, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Could not write complete staged publication file.');
                }

                hash_update($hash, $bytes);
                $size += strlen($bytes);
            }

            $digest = hash_final($hash);
            if ($size !== $asset['size_bytes'] || ! fflush($writer) || ! rewind($writer)) {
                throw new RuntimeException('Publication byte count or staging flush failed.');
            }

            $check = hash_init('sha512');
            if (hash_update_stream($check, $writer) !== $size || hash_final($check) !== $digest) {
                throw new RuntimeException('Staged publication digest does not match verified Vault bytes.');
            }

            $destination = $this->target($root, $relative);
            if (file_exists($destination)) {
                $existing = fopen($destination, 'rb');
                if ($existing === false) {
                    throw new RuntimeException('Could not verify existing destination.');
                }

                try {
                    $before = fstat($existing);
                    $check = hash_init('sha512');
                    $length = hash_update_stream($check, $existing);
                    $this->target($root, $relative);
                    $after = lstat($destination);
                    if (
                        $before === false
                        || $after === false
                        || $before['dev'] !== $after['dev']
                        || $before['ino'] !== $after['ino']
                        || $length !== $size
                        || hash_final($check) !== $digest
                    ) {
                        throw new RuntimeException('Existing destination differs from verified Vault bytes: ' . $relative);
                    }
                } finally {
                    fclose($existing);
                }

                return;
            }

            $this->roots->canonical($workspace);
            $staged = fstat($writer);
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Could not atomically publish staged file.');
            }

            $this->target($root, $relative);
            $published = lstat($destination);
            if ($staged === false || $published === false || $staged['dev'] !== $published['dev'] || $staged['ino'] !== $published['ino'] || $published['size'] !== $size) {
                throw new RuntimeException('Published path no longer names the verified staged file.');
            }
        } finally {
            $reader?->close();
            fclose($writer);
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }
}

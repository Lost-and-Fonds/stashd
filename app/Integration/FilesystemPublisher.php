<?php

declare(strict_types=1);

namespace App\Integration;

use App\Administration\VaultConfig;
use App\Integration\DTO\PublishedFile;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Cryptography\Signing\Signature;
use Tempest\Cryptography\Signing\Signer;

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
     * Reuse local path policy and saved roots; authenticate workspace claims with Tempest's signer.
     */
    public function __construct(
        private FilesystemRoots $roots,
        private IntegrationConfigurations $configurations,
        private VaultConfig $vault,
        private Signer $signer,
    ) {}

    /**
     * Preflight the entire selection on disk, then copy and report each complete file.
     *
     * The private destination-local workspace bounds memory and owns all temporary
     * files. Authenticated, locally locked ownership survives process death so the
     * same frozen run can reclaim abandoned files without trusting a filename alone.
     */
    public function publish(BroadcastInvocation $invocation, array $settings): FileReportStatus
    {
        $configured = $settings['root'] ?? null;
        if (! is_string($configured)) {
            throw new RuntimeException('Filesystem Broadcast requires a configured root.');
        }

        $root = $this->checkRoot($configured);
        $this->directory($root);
        $this->reclaim($root, $invocation->run);
        $workspace = $root . '/.stashd-publication-' . bin2hex(random_bytes(16));
        if (! mkdir($workspace, 0o700)) {
            throw new RuntimeException('Could not create private publication workspace.');
        }

        $plan = null;
        $owner = null;
        $receipt = '';
        try {
            $identity = $this->entry($workspace);
            $claim = $this->ownership($root, $invocation->run, $workspace, (string) $identity['dev'], (string) $identity['ino']);
            $receipt = $workspace . '.' . $identity['dev'] . '.' . $identity['ino'] . '.' . $this->signer->sign($claim)->value . '.owner';
            $owner = fopen($receipt, 'x+b');
            if ($owner === false) {
                throw new RuntimeException('Could not claim private publication workspace.');
            }

            if (! flock($owner, LOCK_EX | LOCK_NB)) {
                fclose($owner);
                $owner = null;
                throw new RuntimeException('A live local owner claimed the publication workspace.');
            }

            if (! chmod($receipt, 0o600)) {
                throw new RuntimeException('Could not protect publication ownership receipt.');
            }

            $this->requireOwner($root, $invocation->run, $workspace, $receipt, $owner);
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

            if (is_resource($owner)) {
                try {
                    $this->cleanup($root, $invocation->run, $workspace, $receipt, $owner);
                } finally {
                    flock($owner, LOCK_UN);
                    fclose($owner);
                }
            }
        }
    }

    /**
     * Discover receipts without treating matching names as deletion authority.
     * Other runs, unowned legacy workspaces and ambiguous material obstruct publication.
     */
    private function reclaim(string $root, string $run): void
    {
        $receipts = [];
        $directories = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            $name = $entry->getFilename();
            if (! str_starts_with($name, '.stashd-publication-')) {
                continue;
            }

            $path = $entry->getPathname();
            $parts = [];
            if (preg_match('/^(\.stashd-publication-[a-f0-9]{32})\.[0-9]+\.[0-9]+\.[a-f0-9]{64,128}\.owner$/D', $name, $parts)) {
                $workspace = $root . '/' . $parts[1];
                if (isset($receipts[$workspace])) {
                    throw new RuntimeException('Contradictory publication ownership obstructs recovery.');
                }

                $receipts[$workspace] = $path;
            } elseif (preg_match('/^\.stashd-publication-[a-f0-9]{32}$/D', $name)) {
                $directories[] = $path;
            } else {
                throw new RuntimeException('Unknown publication material obstructs recovery: ' . $path);
            }
        }

        foreach ($directories as $workspace) {
            if (! isset($receipts[$workspace])) {
                throw new RuntimeException('Unowned publication workspace obstructs recovery: ' . $workspace);
            }
        }

        foreach ($receipts as $workspace => $receipt) {
            $stat = $this->entry($receipt);
            if (($stat['mode'] & 0o170_000) !== 0o100_000 || $stat['nlink'] !== 1 || $stat['size'] !== 0) {
                throw new RuntimeException('Unsafe publication ownership receipt: ' . $receipt);
            }

            $owner = fopen($receipt, 'r+b');
            if ($owner === false) {
                throw new RuntimeException('Could not open publication ownership receipt.');
            }

            try {
                if (! flock($owner, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('A live local owner still uses the publication workspace.');
                }

                $this->cleanup($root, $run, $workspace, $receipt, $owner);
            } finally {
                flock($owner, LOCK_UN);
                fclose($owner);
            }
        }
    }

    /**
     * Authenticate the run, configured root identity and exact workspace identity.
     * The receipt remains outside the directory until reclamation has removed it.
     */
    private function ownership(string $root, string $run, string $workspace, string $device, string $inode): string
    {
        $this->roots->canonical($root);
        $stat = $this->entry($root);

        return json_encode([
            'stashd-filesystem-workspace-1',
            $run,
            $root,
            (string) $stat['dev'],
            (string) $stat['ino'],
            basename($workspace),
            $device,
            $inode,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Recheck the signed claim and the still-open local ownership lock's inode.
     * Identifiers alone and a copied receipt cannot authorize another directory.
     *
     * @param resource $owner Locked ownership receipt; the caller retains it throughout reclamation.
     */
    private function requireOwner(string $root, string $run, string $workspace, string $receipt, mixed $owner): void
    {
        $parts = [];
        if (! preg_match('/^' . preg_quote($workspace, '/') . '\.([0-9]+)\.([0-9]+)\.([a-f0-9]{64,128})\.owner$/D', $receipt, $parts)) {
            throw new RuntimeException('Malformed publication ownership receipt.');
        }

        $stat = $this->entry($receipt);
        $opened = fstat($owner);
        if (
            $opened === false
            || $stat['dev'] !== $opened['dev']
            || $stat['ino'] !== $opened['ino']
            || ($stat['mode'] & 0o170_000) !== 0o100_000
            || $stat['nlink'] !== 1
            || $stat['size'] !== 0
        ) {
            throw new RuntimeException('Publication ownership receipt changed or is unsafe.');
        }

        if (! $this->signer->verify($this->ownership($root, $run, $workspace, $parts[1], $parts[2]), new Signature($parts[3]))) {
            throw new RuntimeException('Publication ownership does not bind this frozen run and destination.');
        }

        clearstatcache(true, $workspace);
        if (file_exists($workspace) || is_link($workspace)) {
            $directory = $this->entry($workspace);
            if (($directory['mode'] & 0o170_000) !== 0o040_000 || (string) $directory['dev'] !== $parts[1] || (string) $directory['ino'] !== $parts[2]) {
                throw new RuntimeException('Publication workspace directory identity changed.');
            }
        }
    }

    /**
     * Remove only known regular entries while retaining ownership through every unlink.
     * Missing entries are already reclaimed; a crash after rmdir leaves a verifiable receipt.
     * Unknown entries or links are retained, never recursively traversed or deleted.
     *
     * @param resource $owner Locked ownership receipt, released only by the caller.
     */
    private function cleanup(string $root, string $run, string $workspace, string $receipt, mixed $owner): void
    {
        $this->requireOwner($root, $run, $workspace, $receipt, $owner);
        $entries = [];
        if (is_dir($workspace)) {
            foreach (new \DirectoryIterator($workspace) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $name = $entry->getFilename();
                $path = $entry->getPathname();
                $stat = $this->entry($path);
                if (
                    ! in_array($name, ['plan', 'bytes'], true) && ! preg_match('/^name-[a-f0-9]{64}$/D', $name)
                    || ($stat['mode'] & 0o170_000) !== 0o100_000
                    || $stat['nlink'] !== 1
                ) {
                    throw new RuntimeException('Unknown or unsafe publication entry obstructs recovery: ' . $path);
                }

                $entries[$path] = $stat;
            }

            foreach ($entries as $path => $before) {
                $this->requireOwner($root, $run, $workspace, $receipt, $owner);
                $current = $this->entry($path);
                if ($current['dev'] !== $before['dev'] || $current['ino'] !== $before['ino'] || $current['mode'] !== $before['mode'] || $current['nlink'] !== 1) {
                    throw new RuntimeException('Publication entry changed during reclamation.');
                }

                if (! unlink($path)) {
                    throw new RuntimeException('Could not reclaim publication entry.');
                }
            }

            $this->requireOwner($root, $run, $workspace, $receipt, $owner);
            if (! rmdir($workspace)) {
                throw new RuntimeException('Could not remove reclaimed publication workspace.');
            }
        }

        $this->requireOwner($root, $run, $workspace, $receipt, $owner);
        if (! unlink($receipt)) {
            throw new RuntimeException('Could not remove reclaimed publication ownership receipt.');
        }
    }

    /**
     * Read a fresh inode without following a symlink or treating an I/O error as absence.
     *
     * @return array{dev: int, ino: int, mode: int, nlink: int, size: int, ...}
     */
    private function entry(string $path): array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false) {
            throw new RuntimeException('Could not inspect publication entry: ' . $path);
        }

        return $stat;
    }

    /**
     * Discard only this copy's still-identical regular staging file, never a replacement.
     *
     * @param array{dev: int, ino: int, ...} $workspaceStat Original workspace identity.
     * @param array|false $staged Writer's identity before closing its handle.
     */
    private function discardStaging(string $workspace, array $workspaceStat, string $temporary, array|false $staged): void
    {
        clearstatcache(true, $temporary);
        if (! file_exists($temporary) && ! is_link($temporary)) {
            return;
        }

        $this->roots->canonical($workspace);
        $directory = $this->entry($workspace);
        $current = $this->entry($temporary);
        if (
            $directory['dev'] !== $workspaceStat['dev']
            || $directory['ino'] !== $workspaceStat['ino']
            || $staged === false
            || $current['dev'] !== $staged['dev']
            || $current['ino'] !== $staged['ino']
            || ($current['mode'] & 0o170_000) !== 0o100_000
            || $current['nlink'] !== 1
        ) {
            throw new RuntimeException('Staged publication entry changed; retaining it for inspection.');
        }

        if (! unlink($temporary)) {
            throw new RuntimeException('Could not remove owned publication staging file.');
        }
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
            $staged = fstat($writer);
            fclose($writer);
            $this->discardStaging($workspace, $workspaceStat, $temporary, $staged);
        }
    }
}

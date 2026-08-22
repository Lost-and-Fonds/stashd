<?php

declare(strict_types=1);

namespace App\System\Storage;

final class FilesystemProbe
{
    public function probeWritable(string $path): FilesystemProbeResult
    {
        if (! is_dir($path)) {
            return FilesystemProbeResult::failed(
                message: "Directory does not exist: {$path}",
                errorCode: 'storage_root_missing',
            );
        }

        if (! is_readable($path)) {
            return FilesystemProbeResult::failed(
                message: "Directory is not readable: {$path}",
                errorCode: 'storage_root_unavailable',
            );
        }

        if (! is_writable($path)) {
            return FilesystemProbeResult::failed(
                message: "Directory is not writable: {$path}",
                errorCode: 'storage_root_unwritable',
            );
        }

        $probeFile = rtrim($path, '/') . '/.stashd-write-test-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($probeFile, 'stashd');

        if ($written === false) {
            return FilesystemProbeResult::failed(
                message: "Write probe failed for: {$path}",
                errorCode: 'storage_root_unwritable',
            );
        }

        @unlink($probeFile);

        return FilesystemProbeResult::ok("Writable: {$path}");
    }

    public function probeHardlinkWithinRoot(string $path): FilesystemProbeResult
    {
        $writable = $this->probeWritable($path);
        if (! $writable->ok) {
            return $writable;
        }

        return $this->probeHardlink(
            source: rtrim($path, '/') . '/.stashd-hardlink-source',
            target: rtrim($path, '/') . '/.stashd-hardlink-target',
            context: "within {$path}",
        );
    }

    public function probeHardlinkCrossRoot(string $sourceRoot, string $targetRoot): FilesystemProbeResult
    {
        $sourceWritable = $this->probeWritable($sourceRoot);
        if (! $sourceWritable->ok) {
            return FilesystemProbeResult::failed(
                message: "Vault is not writable; cannot test hardlinks: {$sourceWritable->message}",
                errorCode: $sourceWritable->errorCode,
            );
        }

        $targetWritable = $this->probeWritable($targetRoot);
        if (! $targetWritable->ok) {
            return FilesystemProbeResult::failed(
                message: "Broadcasts root is not writable; cannot test hardlinks: {$targetWritable->message}",
                errorCode: $targetWritable->errorCode,
            );
        }

        return $this->probeHardlink(
            source: rtrim($sourceRoot, '/') . '/.stashd-vault-hardlink-source',
            target: rtrim($targetRoot, '/') . '/.stashd-broadcast-hardlink-target',
            context: "from {$sourceRoot} to {$targetRoot}",
        );
    }

    private function safeUnlink(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    private function probeHardlink(string $source, string $target, string $context): FilesystemProbeResult
    {
        $this->safeUnlink($source);
        $this->safeUnlink($target);

        if (@file_put_contents($source, 'stashd-hardlink-probe') === false) {
            return FilesystemProbeResult::failed(
                message: "Could not create hardlink probe file {$context}.",
                errorCode: 'storage_hardlink_probe_failed',
            );
        }

        clearstatcache();

        $linked = @link($source, $target);
        if (! $linked) {
            $error = error_get_last()['message'] ?? 'link() returned false';
            $this->safeUnlink($source);
            $this->safeUnlink($target);

            return FilesystemProbeResult::failed(
                message: "Hardlinks are unavailable {$context}. {$error}",
                errorCode: 'storage_hardlink_unavailable',
            );
        }

        $sameInode = file_exists($source) && file_exists($target)
            && @fileinode($source) === @fileinode($target);

        $this->safeUnlink($source);
        $this->safeUnlink($target);

        if (! $sameInode) {
            return FilesystemProbeResult::failed(
                message: "Hardlink probe {$context} did not share an inode.",
                errorCode: 'storage_hardlink_unavailable',
            );
        }

        return FilesystemProbeResult::ok("Hardlinks available {$context}.");
    }
}

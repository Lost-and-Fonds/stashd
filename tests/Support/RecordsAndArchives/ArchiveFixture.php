<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\IncompleteTestError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Holds one disposable filesystem workspace per acceptance scenario.
 *
 * External DB schemas, workers and queues must also be isolated by the
 * concrete ArchivesDriver. Never use an existing production Vault.
 */
final class ArchiveFixture
{
    private string $workspace;

    public function __construct()
    {
        $this->workspace = sys_get_temp_dir() . '/stashd-archives-' . bin2hex(random_bytes(12));

        if (!mkdir($this->workspace, 0700, true)) {
            throw new RuntimeException('Unable to create the acceptance workspace.');
        }
    }

    /**
     * Return a path strictly inside this test's workspace.
     */
    public function path(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new InvalidArgumentException('Fixture path must remain inside the test workspace.');
        }

        return $this->workspace . '/' . $relative;
    }

    /**
     * Write input bytes outside archival storage.
     */
    public function input(string $name, string $bytes): string
    {
        $path = $this->path('inputs/' . $name);
        $parent = dirname($path);

        if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
            throw new RuntimeException('Unable to create fixture input directory.');
        }

        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Unable to create fixture input file.');
        }

        return $path;
    }

    /**
     * Exercise the real application through a separately configured adapter.
     *
     * Missing implementation marks the test INCOMPLETE rather than green.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $operation, array $arguments = []): array
    {
        $class = getenv('STASHD_ARCHIVES_ACCEPTANCE_DRIVER');

        if (!$class) {
            throw new IncompleteTestError(
                'Connect a real ArchivesDriver using STASHD_ARCHIVES_ACCEPTANCE_DRIVER.',
            );
        }

        if (!class_exists($class) || !is_a($class, ArchivesDriver::class, true)) {
            throw new RuntimeException('The acceptance driver must implement ArchivesDriver.');
        }

        /** @var ArchivesDriver $driver */
        $driver = new $class();

        return $driver->run($operation, [
            'workspace' => $this->workspace,
            'primary' => $this->path('vault'),
            ...$arguments,
        ]);
    }

    /**
     * Delete only this workspace, including test primary and replica roots.
     */
    public function dispose(): void
    {
        if (!is_dir($this->workspace)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->workspace);
    }
}

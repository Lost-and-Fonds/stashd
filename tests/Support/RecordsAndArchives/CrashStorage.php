<?php

namespace Tests\Support\RecordsAndArchives;

use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;

/**
 * Inject deterministic process death around real local publication I/O.
 *
 * The selected CRASH_BOUNDARY kills the child without exception cleanup so
 * acceptance tests can inspect the physical files left at each boundary.
 */
readonly class CrashStorage extends LocalArchiveStorage
{
    /**
     * Kill the current process when the requested crash boundary is selected.
     *
     * Writes a diagnostic before SIGKILL and has a forced-exit fallback. Run this
     * provider only in disposable child processes, never the main test runner.
     */
    private function stop(string $boundary): void
    {
        if (getenv('CRASH_BOUNDARY') === $boundary) {
            fwrite(STDERR, "CRASH:{$boundary}\n");
            posix_kill(getmypid(), 9);
            usleep(1_000_000);
            exit(99);
        }
    }

    /**
     * Perform real byte writes with optional partial-write or completed-write crashes.
     *
     * Classifies content, version inventories and root publication files so tests
     * can observe precisely where interrupted publication leaves evidence.
     */
    public function write(string $location, string $contents): static
    {
        $rootInventory = preg_match('~/inventory\.json(?:\.tmp-[^/]+)?$~', $location) && ! preg_match('~/v\d+(?:\.tmp-[^/]+)?/~', $location);
        $rootSidecar = str_contains($location, '/inventory.json.sha512') && ! preg_match('~/v\d+(?:\.tmp-[^/]+)?/~', $location);
        $kind = $rootSidecar ? 'root-sidecar' : ($rootInventory ? 'root-inventory' : (str_ends_with($location, '/inventory.json') ? 'version-inventory' : 'content'));
        if (getenv('CRASH_BOUNDARY') === "{$kind}-partial" && ($kind !== 'content' || str_contains($location, '/content/'))) {
            parent::write($location, substr($contents, 0, max(1, intdiv(strlen($contents), 2))));
            $this->stop("{$kind}-partial");
        }
        parent::write($location, $contents);
        if (str_contains($location, '/v') && str_contains($location, '/content/')) {
            $this->stop('content-complete');
        }
        if (str_ends_with($location, '/inventory.json.sha512') && preg_match('~/v\d+\.tmp-[^/]+/~', $location)) {
            $this->stop('version-ready');
        }
        return $this;
    }

    /**
     * Crash after partial or complete content acquisition at the selected boundary.
     *
     * The partial case consumes only three input bytes. Stream cleanup is
     * intentionally bypassed when the process is killed.
     */
    public function writeStream(string $location, mixed $contents): static
    {
        if (getenv('CRASH_BOUNDARY') === 'content-partial') {
            parent::write($location, (string) fread($contents, 3));
            $this->stop('content-partial');
        }
        parent::writeStream($location, $contents);
        $this->stop('content-complete');
        return $this;
    }

    /**
     * Perform the real atomic rename, then crash at its publication boundary.
     *
     * Distinguishes version publication, root inventory and root sidecar replacement.
     */
    public function move(string $source, string $destination): static
    {
        parent::move($source, $destination);
        if (preg_match('~/v\d+$~', $destination)) {
            $this->stop('version-published');
        } elseif (str_ends_with($destination, '/inventory.json')) {
            $this->stop('root-inventory-published');
        } elseif (str_ends_with($destination, '/inventory.json.sha512')) {
            $this->stop(preg_match('~/v\d+\.tmp-[^/]+/~', $destination) ? 'version-ready' : 'root-sidecar-published');
        }
        return $this;
    }

    /**
     * Inject a crash before pending-metadata cleanup, otherwise check real storage.
     */
    public function fileExists(string $location): bool
    {
        if (str_ends_with($location, '/.ocfl-pending-meta.json')) {
            $this->stop('before-cleanup');
        }
        return parent::fileExists($location);
    }

    /**
     * Inject the selected pre-cleanup crash before delegating a destructive file deletion.
     */
    public function delete(string $location): static
    {
        $this->stop('before-cleanup');
        return parent::delete($location);
    }
}

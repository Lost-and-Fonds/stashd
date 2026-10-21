<?php

namespace Tests\Support\RecordsAndArchives;

use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;

/** Real local I/O with deterministic process death; never throws for cleanup. */
readonly class CrashStorage extends LocalArchiveStorage
{
    private function stop(string $boundary): void
    {
        if (getenv('CRASH_BOUNDARY') === $boundary) {
            fwrite(STDERR, "CRASH:{$boundary}\n");
            posix_kill(getmypid(), 9);
            usleep(1_000_000);
            exit(99);
        }
    }

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

    public function fileExists(string $location): bool
    {
        if (str_ends_with($location, '/.ocfl-pending-meta.json')) {
            $this->stop('before-cleanup');
        }
        return parent::fileExists($location);
    }

    public function delete(string $location): static
    {
        $this->stop('before-cleanup');
        return parent::delete($location);
    }
}

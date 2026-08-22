<?php

declare(strict_types=1);

namespace App\Console;

use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRecord;
use App\Config\StashdConfig;
use App\System\Storage\PathSanitizer;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Console\Middleware\CautionMiddleware;

use function Tempest\Support\Filesystem\create_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

/**
 * One-time operator step for upgrading to type-aware, name-keyed broadcast
 * paths: renames each broadcast's on-disk root from the old
 * {broadcastsPath}/{broadcastId} scheme to the new
 * {broadcastsPath}/{type}/{name} (or destination_path override) scheme, and
 * rewrites persisted asset paths to match. Same filesystem, so this is an
 * atomic rename(), not a re-copy. Idempotent -- safe to re-run.
 */
final readonly class RelocateBroadcastPathsCommand
{
    use HasConsole;

    public function __construct(
        private StashdConfig $config,
        private BroadcastPathBuilder $paths,
        private AssetRepository $assets,
    ) {}

    #[ConsoleCommand(
        name: 'stashd:relocate-broadcast-paths',
        description: 'One-time migration: move broadcast output from the old {broadcastId}-keyed default path to the new type+name-keyed scheme.',
        middleware: [CautionMiddleware::class],
    )]
    public function __invoke(): ExitCode
    {
        $broadcasts = BroadcastRecord::all();
        $relocated = 0;
        $skipped = 0;

        foreach ($broadcasts as $broadcast) {
            $oldRoot = rtrim($this->config->broadcastsPath(), '/') . '/' . PathSanitizer::sanitizeSegment((string) $broadcast->id);
            $newRoot = $this->paths->broadcastRoot($broadcast);

            if ($oldRoot === $newRoot || ! is_dir($oldRoot)) {
                $skipped++;

                continue;
            }

            try {
                $this->paths->assertOwnsRoot($broadcast);
            } catch (BroadcastException) {
                $this->console->error("Skipping {$broadcast->name} ({$broadcast->id}): {$newRoot} already exists and is owned by a different broadcast.");
                $skipped++;

                continue;
            }

            if (is_dir($newRoot)) {
                // Already at the new location (e.g. a re-run after a prior
                // partial move, or the ownership check above already
                // confirmed it's this broadcast's own directory) -- nothing
                // left to do.
                $skipped++;

                continue;
            }

            try {
                create_directory(dirname($newRoot), 0o775);
            } catch (FilesystemException) {
                $this->console->error("Skipping {$broadcast->name} ({$broadcast->id}): could not create {$newRoot}'s parent directory.");
                $skipped++;

                continue;
            }

            if (! rename($oldRoot, $newRoot)) {
                $this->console->error("Skipping {$broadcast->name} ({$broadcast->id}): could not move {$oldRoot} to {$newRoot}.");
                $skipped++;

                continue;
            }

            file_put_contents(rtrim($newRoot, '/') . '/.stashd-broadcast', (string) $broadcast->id);
            $this->relocateAssetPaths((string) $broadcast->id, $oldRoot, $newRoot);

            $this->console->keyValue($broadcast->name, "{$oldRoot} -> {$newRoot}");
            $relocated++;
        }

        $this->console->success("Relocated {$relocated} broadcast(s), skipped {$skipped} (already migrated, never built, or unset).");

        if ($relocated > 0) {
            $this->console->warning('If any of these broadcasts are already scanned by an external media library, update that library\'s path and re-run a scan.');
        }

        return ExitCode::SUCCESS;
    }

    private function relocateAssetPaths(string $broadcastId, string $oldRoot, string $newRoot): void
    {
        $assets = $this->assets->listByBroadcastAndRole(BroadcastId::parse($broadcastId), AssetRole::Hardlink);

        foreach ($assets as $asset) {
            if ($asset->path !== null && str_starts_with($asset->path, $oldRoot . '/')) {
                $asset->path = $newRoot . substr($asset->path, strlen($oldRoot));
                $asset->save();
            }
        }
    }
}

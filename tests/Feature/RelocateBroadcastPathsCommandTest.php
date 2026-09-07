<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\BroadcastState;
use App\Config\StashdConfig;
use App\Console\RelocateBroadcastPathsCommand;
use App\Stashes\StashId;
use App\System\Storage\PathSanitizer;
use App\Vault\AssetId;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use Tempest\Console\ExitCode;

test('relocation command moves an old ID-keyed broadcast root to the new type+name-keyed root and rewrites asset paths', function (): void {
    [$headers, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('relocate-command');

    $broadcasts = $this->container->get(BroadcastRepository::class);
    $broadcast = $broadcasts->create(
        stashId: StashId::parse($stashId),
        type: 'jellyfin',
        name: 'Relocatable Show',
        slug: 'relocatable-show',
        state: BroadcastState::Ready,
    );

    $config = $this->container->get(StashdConfig::class);
    $oldRoot = rtrim($config->broadcastsPath(), '/') . '/' . PathSanitizer::sanitizeSegment((string) $broadcast->id);
    mkdir($oldRoot . '/Season 01', 0775, true);
    $oldFilePath = $oldRoot . '/Season 01/001 - Episode.fake';
    file_put_contents($oldFilePath, 'episode-bytes');

    $assets = $this->container->get(AssetRepository::class);
    $asset = $assets->create(
        itemId: ItemId::parse($itemId),
        role: AssetRole::Hardlink,
        kind: AssetKind::Video,
        state: AssetState::Ready,
        path: $oldFilePath,
        relativePath: 'Season 01/001 - Episode.fake',
    );
    $asset->broadcastId = BroadcastId::parse((string) $broadcast->id);
    $asset->save();

    $command = $this->container->get(RelocateBroadcastPathsCommand::class);
    expect($command())->toBe(ExitCode::SUCCESS);

    $paths = $this->container->get(BroadcastPathBuilder::class);
    $newRoot = $paths->broadcastRoot($broadcast);
    $newFilePath = $newRoot . '/Season 01/001 - Episode.fake';

    expect(is_dir($oldRoot))->toBeFalse()
        ->and(is_file($newFilePath))->toBeTrue()
        ->and(file_get_contents($newFilePath))->toBe('episode-bytes')
        ->and(is_file($newRoot . '/.stashd-broadcast'))->toBeTrue();

    $reloaded = $assets->find(AssetId::parse((string) $asset->id));
    expect($reloaded->path)->toBe($newFilePath);

    // Idempotent: re-running does nothing further and still reports success.
    expect($command())->toBe(ExitCode::SUCCESS);
    expect(is_file($newFilePath))->toBeTrue();
});

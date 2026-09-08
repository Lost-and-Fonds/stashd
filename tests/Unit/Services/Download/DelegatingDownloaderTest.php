<?php

declare(strict_types=1);

use App\Downloads\DelegatingDownloader;
use App\Downloads\DownloadException;
use App\Downloads\DownloadRequest;
use App\Downloads\Fake\FakeDownloader;
use App\Plugins\ExternalInputPluginRegistry;
use App\Providers\StashdUri;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashId;
use App\Vault\ItemId;
use App\Vault\VaultSidecarBuilder;

function delegatingDownloaderRequest(string $providerKey = 'fake'): DownloadRequest
{
    return new DownloadRequest(
        itemId: ItemId::parse('media_01J00000000000000000000001'),
        stashId: StashId::parse('stash_01J00000000000000000000001'),
        providerKey: $providerKey,
        providerItemId: 'demo-episode-1',
        canonicalUri: StashdUri::fake('item/demo-episode-1'),
        downloadPolicy: DownloadPolicy::Video,
        tempDirectory: sys_get_temp_dir(),
    );
}

test('delegating downloader routes registered downloaders without provider-specific branches', function (): void {
    $fake = new FakeDownloader(new VaultSidecarBuilder());
    $registry = new ExternalInputPluginRegistry([], [], ['fake' => $fake]);
    $downloader = new DelegatingDownloader($registry);

    expect($downloader->probe()->available)->toBeTrue()
        ->and($downloader->download(delegatingDownloaderRequest())->implementation)->toBe('fake');
});

test('delegating downloader rejects unregistered providers', function (): void {
    $downloader = new DelegatingDownloader(new ExternalInputPluginRegistry([], []));

    $downloader->download(delegatingDownloaderRequest('missing'));
})->throws(DownloadException::class, 'No external Input plugin is registered for provider missing.');

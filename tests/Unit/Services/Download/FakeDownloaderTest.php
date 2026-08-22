<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Download;

use App\Downloads\DownloadException;
use App\Downloads\DownloadRequest;
use App\Downloads\Fake\FakeDownloader;
use App\Providers\StashdUri;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashId;
use App\Vault\MediaItemId;
use App\Vault\VaultSidecarBuilder;

test('fake downloader writes deterministic files to temp directory', function (): void {
    $temp = sys_get_temp_dir() . '/stashd-fake-download-' . bin2hex(random_bytes(4));
    mkdir($temp, 0775, true);

    $downloader = new FakeDownloader(new VaultSidecarBuilder());
    $request = new DownloadRequest(
        mediaItemId: MediaItemId::parse('media_01J00000000000000000000001'),
        stashId: StashId::parse('stash_01J00000000000000000000001'),
        providerKey: 'fake',
        providerItemId: 'demo-episode-1',
        canonicalUri: StashdUri::fake('item/demo-episode-1'),
        downloadPolicy: DownloadPolicy::Video,
        tempDirectory: $temp,
    );

    $result = $downloader->download($request);

    expect($result->implementation)->toBe('fake')
        ->and($result->files)->not->toBeEmpty()
        ->and(file_exists($temp . '/original.fake'))->toBeTrue()
        ->and(file_exists($temp . '/metadata.json'))->toBeTrue()
        ->and(file_exists($temp . '/source.json'))->toBeTrue();

    array_map('unlink', glob($temp . '/*') ?: []);
    rmdir($temp);
});

test('fake downloader rejects metadata-only policy', function (): void {
    $downloader = new FakeDownloader(new VaultSidecarBuilder());
    $request = new DownloadRequest(
        mediaItemId: MediaItemId::parse('media_01J00000000000000000000001'),
        stashId: StashId::parse('stash_01J00000000000000000000001'),
        providerKey: 'fake',
        providerItemId: 'demo-episode-1',
        canonicalUri: StashdUri::fake('item/demo-episode-1'),
        downloadPolicy: DownloadPolicy::MetadataOnly,
        tempDirectory: sys_get_temp_dir(),
    );

    $downloader->download($request);
})->throws(DownloadException::class);

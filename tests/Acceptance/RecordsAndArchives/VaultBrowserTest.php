<?php

use Tests\Support\RecordsAndArchives\BrowserServer;
use Tests\Support\RecordsAndArchives\OcflInspector;

function browser_item_url(string $id, string $version): string
{
    return '/items/' . rawurlencode($id) . '?version=' . $version;
}

function browser_asset_url(string $id, string $version): string
{
    return '/assets/' . rawurlencode($id) . '/' . $version;
}

test('HTTP browser reconstructs exact historical pins and streams real archived bytes after restart', function (): void {
    $this->archive->run('vault.initialize');
    $uri = 'urn:test:web-history';
    $item = $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => '<script>alert("owned")</script>']]);
    $source = $this->archive->input('binary', "original\0bytes\xff");
    $digest1 = hash_file('sha512', $source);
    $asset = $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['folder/original.bin' => $source]]);
    $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Original'], 'pins' => [['object_id' => $asset['object_id'], 'version' => 'v1']]]);
    file_put_contents($source, "revised\0bytes\xfe");
    $digest2 = hash_file('sha512', $source);
    $this->archive->run('asset.preserve', ['item_uri' => $uri, 'asset_key' => 'master', 'files' => ['folder/original.bin' => $source]]);
    $this->archive->run('item.record', ['uri' => $uri, 'metadata' => ['title' => 'Revised'], 'pins' => [['object_id' => $asset['object_id'], 'version' => 'v2']]]);
    unlink($source);
    $root = $this->archive->path('vault');
    $server = new BrowserServer($root, $this->archive->path('http.log'));
    try {
        $overview = $server->request('/');
        expect($overview['status'])->toBe(200)->and($overview['body'])->toContain($uri, 'Revised', 'v3');
        $metadata = $server->request(browser_item_url($item['object_id'], 'v1'));
        expect($metadata['status'])->toBe(200)->and($metadata['body'])->toContain('Metadata-only revision', '&lt;script&gt;')->not->toContain('<script>alert');
        $server->stop();
        $server = new BrowserServer($root, $this->archive->path('restart.log'));
        foreach ([
            ['v2', 'v1', $digest1],
            ['v3', 'v2', $digest2],
        ] as [$revision, $version, $digest]) {
            $page = $server->request(browser_item_url($item['object_id'], $revision));
            $assetUrl = browser_asset_url($asset['object_id'], $version);
            expect($page['status'])
                ->toBe(200)
                ->and($page['body'])
                ->toContain($assetUrl, $digest)
                ->not->toContain($version === 'v1' ? $digest2 : $digest1);
            $detail = $server->request($assetUrl);
            expect($detail['status'])->toBe(200)->and($detail['body'])->toContain($uri, 'folder/original.bin', $digest);
            $download = $server->request($assetUrl . '/download?path=folder%2Foriginal.bin');
            expect($download['status'])
                ->toBe(200)
                ->and(hash('sha512', $download['body']))
                ->toBe($digest)
                ->and($download['headers'])
                ->toContain('application/octet-stream', 'attachment;', 'nosniff');
        }
        foreach ([
            browser_item_url($item['object_id'], 'v999'),
            browser_asset_url($asset['object_id'], 'v999'),
            '/items/no-such-id',
            browser_item_url($item['object_id'], 'latest'),
            browser_asset_url($asset['object_id'], 'v1') . '/download?path=..%2Fitem.json',
            browser_asset_url($asset['object_id'], 'v1') . '/download?path=.stashd%2Fasset.json',
            browser_asset_url($asset['object_id'], 'v1') . '/download?path[]=a',
        ] as $path) {
            expect($server->request($path)['status'])->toBeGreaterThanOrEqual(400);
        }
        expect($server->request('/items/import', method: 'POST')['status'])->toBeGreaterThanOrEqual(400);
        $found = OcflInspector::inventoryFor($root, $asset['object_id']);
        $physical = dirname($found['path']) . '/' . $found['inventory']['manifest'][$digest1][0];
        file_put_contents($physical, 'corrupt');
        expect($server->request(browser_asset_url($asset['object_id'], 'v1') . '/download?path=folder%2Foriginal.bin')['status'])->toBe(404);
        expect($server->request(browser_item_url($item['object_id'], 'v2'))['body'])->toContain('Pinned Asset unavailable');
        unlink($physical);
        expect($server->request(browser_asset_url($asset['object_id'], 'v1') . '/download?path=folder%2Foriginal.bin')['status'])->toBe(404);
    } finally {
        $server->stop();
    }
});

test('HTTP browser streams a file larger than its PHP memory limit', function (): void {
    $this->archive->run('vault.initialize');
    $source = $this->archive->input('large', '');
    $stream = fopen($source, 'wb');
    $chunk = random_bytes(1024 * 1024);
    for ($i = 0; $i < 64; $i++) {
        fwrite($stream, $chunk);
    }
    fclose($stream);
    unset($chunk);
    $digest = hash_file('sha512', $source);
    $asset = $this->archive->run('asset.preserve', ['item_uri' => 'urn:test:large-http', 'asset_key' => 'master', 'files' => ['large.bin' => $source]]);
    unlink($source);
    $server = new BrowserServer($this->archive->path('vault'), $this->archive->path('http.log'));
    try {
        $destination = $this->archive->path('download');
        $result = $server->request(browser_asset_url($asset['object_id'], 'v1') . '/download?path=large.bin', $destination);
        expect($result['status'])
            ->toBe(200, file_get_contents($this->archive->path('http.log')))
            ->and(filesize($destination))
            ->toBe(64 * 1024 * 1024)
            ->and(hash_file('sha512', $destination))
            ->toBe($digest);
    } finally {
        $server->stop();
    }
});

test('HTTP browser reports uninitialized, empty, unrelated and damaged canonical Objects honestly', function (): void {
    $server = new BrowserServer($this->archive->path('vault'), $this->archive->path('http.log'));
    try {
        expect($server->request('/')['body'])->toContain('Vault not initialized');
        $this->archive->run('vault.initialize');
        expect($server->request('/')['body'])->toContain('No readable Items');
        $object = $this->archive->run('vault.object_create', [
            'type' => 'item',
            'uuid' => 'd9016017-0220-4590-98f0-438b226c3dd8',
            'files' => ['unrelated' => ['contents' => 'not an Item record']],
        ]);
        expect($server->request('/')['body'])->toContain('unreadable or incompatible Item record');
        $this->archive->run('vault.object_create', [
            'type' => 'asset',
            'uuid' => 'd9016017-0220-4590-98f0-438b226c3dd8',
            'files' => ['unrelated' => ['contents' => 'not an Asset record']],
        ]);
        expect($server->request('/')['body'])->toContain('not an Item; omitted');
        $found = OcflInspector::inventoryFor($this->archive->path('vault'), $object['object_id']);
        file_put_contents($found['path'], '{broken');
        expect($server->request('/')['status'])->toBe(503)->and($server->request('/')['body'])->toContain('No complete Item list');
    } finally {
        $server->stop();
    }
});

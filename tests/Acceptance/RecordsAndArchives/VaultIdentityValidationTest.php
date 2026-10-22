<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\BrowserServer;

test('opening a Vault rejects a coerced identity version without modifying storage', function (): void {
    $this->archive->run('vault.initialize');
    $path = $this->archive->path('vault/identity.json');
    $identity = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $identity['version'] = '1';
    $invalid = json_encode($identity, JSON_THROW_ON_ERROR);
    file_put_contents($path, $invalid);

    expect(fn () => $this->archive->run('vault.open'))->toThrow(TypeError::class, 'Vault identity must contain');
    $server = new BrowserServer($this->archive->path('vault'), $this->archive->path('http.log'));
    try {
        $response = $server->request('/');
        expect($response['status'])
            ->toBe(503)
            ->and($response['body'])
            ->toContain('Vault unavailable')
            ->and(file_get_contents($path))
            ->toBe($invalid);
    } finally {
        $server->stop();
    }
});

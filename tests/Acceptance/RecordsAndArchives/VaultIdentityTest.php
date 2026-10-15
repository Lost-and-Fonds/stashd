<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\ArchivesRejected;
use Tests\Support\RecordsAndArchives\OcflInspector;

test('a new installation creates one persistent logical Vault identity', function (): void {
    $created = $this->archive->run('vault.initialize');
    $opened = $this->archive->run('vault.open');

    expect($created['vault_id'])->toBeString()->not->toBeEmpty()
        ->and($opened['vault_id'])->toBe($created['vault_id']);

    OcflInspector::assertRoot($this->archive->path('vault'));
});

test('reopening a Vault in a fresh application context never creates another identity', function (): void {
    $initial = $this->archive->run('vault.initialize');
    $this->archive->run('vault.open');
    $reopened = $this->archive->run('vault.open');

    expect($reopened['vault_id'])->toBe($initial['vault_id']);
});

test('two separately initialized installations are not the same Vault', function (): void {
    $first = $this->archive->run('vault.initialize');
    $second = $this->archive->run('vault.initialize', [
        'primary' => $this->archive->path('another-vault'),
    ]);

    expect($second['vault_id'])->not->toBe($first['vault_id']);

    OcflInspector::assertRoot($this->archive->path('another-vault'));
});

test('an empty new Vault has no archived Items or Assets', function (): void {
    $this->archive->run('vault.initialize');
    $catalogue = $this->archive->run('catalogue.list');

    expect($catalogue['items'])->toBe([])
        ->and($catalogue['assets'])->toBe([]);
});

test('opening an uninitialized storage root cannot silently initialize it', function (): void {
    expect(fn () => $this->archive->run('vault.open'))
        ->toThrow(ArchivesRejected::class);

    expect(file_exists($this->archive->path('vault/0=ocfl_1.1')))->toBeFalse();
});

test('initialization refuses to overwrite unrelated existing storage contents', function (): void {
    $root = $this->archive->path('vault');
    mkdir($root, 0700, true);
    file_put_contents($root . '/existing-important-file', 'must survive');

    expect(fn () => $this->archive->run('vault.initialize'))
        ->toThrow(ArchivesRejected::class);

    expect(file_get_contents($root . '/existing-important-file'))->toBe('must survive');
});

test('initialization cannot replace a previously established Vault identity', function (): void {
    $first = $this->archive->run('vault.initialize');

    try {
        $second = $this->archive->run('vault.initialize');
        expect($second['vault_id'])->toBe($first['vault_id']);
    } catch (ArchivesRejected) {
        // Refusing to initialize an existing Vault is also a safe policy.
    }

    expect($this->archive->run('vault.open')['vault_id'])->toBe($first['vault_id']);
});

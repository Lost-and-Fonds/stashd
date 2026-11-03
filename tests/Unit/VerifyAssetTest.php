<?php

declare(strict_types=1);

use App\RecordsAndArchives\VerifyAsset;

test('verification identity includes the exact Object and version and survives public serialization', function (): void {
    $job = new VerifyAsset('urn:example:asset-one', 'v1');
    $restored = new VerifyAsset(...json_decode(json_encode($job, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect($restored)->toEqual($job);
    expect($restored->key())->toBe($job->key());
    expect($restored->observation())->toBe($job->observation());
    expect(new VerifyAsset($job->objectId, 'v01')->version)->toBe('v01');
    expect(new VerifyAsset($job->objectId, 'v2')->key())->not->toBe($job->key());
    expect(new VerifyAsset('urn:example:asset-two', 'v1')->key())->not->toBe($job->key());
});

test('verification rejects version aliases and malformed exact versions', function (string $version): void {
    expect(fn () => new VerifyAsset('urn:example:asset-one', $version))->toThrow(InvalidArgumentException::class);
})->with(['head', '', 'v0', 'v00', '../v1', "v1\n"]);

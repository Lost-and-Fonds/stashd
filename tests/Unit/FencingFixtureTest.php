<?php

declare(strict_types=1);

use PHPUnit\Framework\IncompleteTestError;
use Tests\Support\OperationsFencing\DisposalOnlyDriver;
use Tests\Support\OperationsFencing\FencingFixture;

test('the fencing fixture distinguishes missing and invalid drivers', function (): void {
    $original = getenv('STASHD_OPERATIONS_FENCING_DRIVER');
    $fixture = new FencingFixture();
    try {
        putenv('STASHD_OPERATIONS_FENCING_DRIVER');
        expect(fn () => $fixture->run('job.prepare'))->toThrow(IncompleteTestError::class);
        putenv('STASHD_OPERATIONS_FENCING_DRIVER=MissingFencingDriver');
        expect(fn () => $fixture->run('job.prepare'))->toThrow(RuntimeException::class);
    } finally {
        $fixture->dispose();
        putenv($original === false ? 'STASHD_OPERATIONS_FENCING_DRIVER' : 'STASHD_OPERATIONS_FENCING_DRIVER=' . $original);
    }
});

test('failed fencing cleanup retains its manifest until disposal succeeds', function (): void {
    $original = getenv('STASHD_OPERATIONS_FENCING_DRIVER');
    $fixture = new FencingFixture();
    try {
        putenv('STASHD_OPERATIONS_FENCING_DRIVER=' . DisposalOnlyDriver::class);
        $workspace = $fixture->run('fixture.path')['workspace'];
        $manifest = $workspace . '/installation.json';
        file_put_contents($manifest, 'disposal-only evidence');
        DisposalOnlyDriver::$rejectDisposal = true;
        expect($fixture->dispose(...))->toThrow(RuntimeException::class, 'Controlled cleanup failure.');
        expect(is_file($manifest))->toBeTrue();
        expect(file_get_contents($manifest))->toBe('disposal-only evidence');
    } finally {
        DisposalOnlyDriver::$rejectDisposal = false;
        try {
            $fixture->dispose();
        } finally {
            putenv($original === false ? 'STASHD_OPERATIONS_FENCING_DRIVER' : 'STASHD_OPERATIONS_FENCING_DRIVER=' . $original);
        }
    }

    expect(is_dir($workspace))->toBeFalse();
});

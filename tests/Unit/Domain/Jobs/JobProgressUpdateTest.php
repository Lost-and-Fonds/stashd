<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Jobs;

use App\Jobs\JobProgressUpdate;

test('ofSteps computes percent from current/total and leaves eta/rate null', function (): void {
    $update = JobProgressUpdate::ofSteps(1, 4, 'Downloading to temp');

    expect($update->current)->toBe(1)
        ->and($update->total)->toBe(4)
        ->and($update->percent)->toBe(25.0)
        ->and($update->label)->toBe('Downloading to temp')
        ->and($update->etaSeconds)->toBeNull()
        ->and($update->rate)->toBeNull();
});

test('ofSteps with a zero total reports zero percent instead of dividing by zero', function (): void {
    $update = JobProgressUpdate::ofSteps(0, 0, 'Starting');

    expect($update->percent)->toBe(0.0);
});

test('ofPercent leaves current/total null and carries an explicit percent/eta', function (): void {
    $update = JobProgressUpdate::ofPercent(63.5, 'Transcoding: 63%', 240);

    expect($update->current)->toBeNull()
        ->and($update->total)->toBeNull()
        ->and($update->percent)->toBe(63.5)
        ->and($update->label)->toBe('Transcoding: 63%')
        ->and($update->etaSeconds)->toBe(240)
        ->and($update->rate)->toBeNull();
});

test('indeterminate progress has a label but no made-up percentage', function (): void {
    $update = JobProgressUpdate::indeterminate('Publishing broadcast');

    expect($update->current)->toBeNull()
        ->and($update->total)->toBeNull()
        ->and($update->percent)->toBeNull()
        ->and($update->label)->toBe('Publishing broadcast');
});

test('indeterminate progress preserves a known approximate byte total', function (): void {
    $update = JobProgressUpdate::indeterminate('Downloading', 123456, true);

    expect($update->percent)->toBeNull()
        ->and($update->sizeBytes)->toBe(123456)
        ->and($update->sizeEstimated)->toBeTrue();
});

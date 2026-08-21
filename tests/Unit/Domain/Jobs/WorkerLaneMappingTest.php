<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Jobs;

use App\Jobs\JobIntent;
use App\Jobs\JobLane;
use App\Jobs\WorkerProcessProbe;

test('every job intent belongs to exactly one lane', function (): void {
    $seen = [];

    foreach (JobLane::cases() as $lane) {
        foreach ($lane->intents() as $intent) {
            expect($seen)->not->toHaveKey($intent->value);
            $seen[$intent->value] = true;
        }
    }

    expect($seen)->toHaveCount(count(JobIntent::cases()));
});

test('bulk work and interactive work are in separate lanes', function (): void {
    expect(JobIntent::Download->lane())->toBe(JobLane::Bulk)
        ->and(JobIntent::Broadcast->lane())->toBe(JobLane::Bulk)
        ->and(JobIntent::InitialBackfill->lane())->toBe(JobLane::Discovery)
        ->and(JobIntent::Preflight->lane())->toBe(JobLane::Interactive)
        ->and(JobIntent::AddInput->lane())->toBe(JobLane::Interactive);
});

test('probe sees its own process as alive', function (): void {
    $probe = new WorkerProcessProbe();

    expect($probe->isAlive($probe->currentToken()))->toBeTrue();
});

test('probe treats a reused pid with a different start time as dead', function (): void {
    $probe = new WorkerProcessProbe();
    $token = $probe->currentToken();

    // Only meaningful where tokens carry a /proc start time (Linux).
    if (! str_contains($token, ':')) {
        expect($probe->isAlive((string) getmypid()))->toBeTrue();

        return;
    }

    [$pid, $startTime] = explode(':', $token);

    expect($probe->isAlive($pid . ':' . ((int) $startTime + 1)))->toBeFalse();
});

test('probe treats a nonexistent pid as dead', function (): void {
    $probe = new WorkerProcessProbe();

    // PIDs are capped well below this on Linux (pid_max <= 2^22).
    expect($probe->isAlive('99999999'))->toBeFalse()
        ->and($probe->isAlive('99999999:12345'))->toBeFalse();
});

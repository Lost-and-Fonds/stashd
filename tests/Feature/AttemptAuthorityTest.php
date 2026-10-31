<?php

declare(strict_types=1);

use Tests\Support\OperationsFencing\FencingFixture;

test('a database mutation delayed past expiry rolls back even with the Job row locked', function (): void {
    $fixture = new FencingFixture();
    try {
        $job = $fixture->run('job.prepare', ['name' => 'delayed-database-mutation'])['job_id'];
        $attempt = $fixture->run('worker.start_paused', ['job_id' => $job]);
        expect($fixture->run('host.slow_write', ['attempt_id' => $attempt['attempt_id']])['accepted'])->toBeFalse();
        expect($fixture->run('host.records', ['job_id' => $job])['checkpoints'])->toBe([]);
    } finally {
        $fixture->dispose();
    }
});

test('a blocking tool retains authority through real periodic lease renewal', function (): void {
    $fixture = new FencingFixture();
    try {
        $job = $fixture->run('job.prepare', ['name' => 'blocking-tool-heartbeat'])['job_id'];
        $attempt = $fixture->run('worker.start_paused', ['job_id' => $job]);
        expect($fixture->run('host.blocking_tool', ['attempt_id' => $attempt['attempt_id']])['accepted'])->toBeTrue();
        expect($fixture->run('attempts.read', ['job_id' => $job])['attempts'])->toHaveCount(1);
        expect($fixture->run('host.try_write', ['attempt_id' => $attempt['attempt_id'], 'kind' => 'checkpoint', 'value' => 'after-tool'])['accepted'])->toBeTrue();
    } finally {
        $fixture->dispose();
    }
});

test('a rejected renewal cannot change history or publish more Work', function (): void {
    $fixture = new FencingFixture();
    try {
        $job = $fixture->run('job.prepare', ['name' => 'rejected-renewal-history'])['job_id'];
        $attempt = $fixture->run('worker.start_paused', ['job_id' => $job]);
        $fixture->run('lease.await_expiration', ['attempt_id' => $attempt['attempt_id']]);
        $beforeJob = $fixture->run('job.read', ['job_id' => $job]);
        $beforeHistory = $fixture->run('attempts.read', ['job_id' => $job]);
        $beforePublications = $fixture->run('transport.publications', ['job_id' => $job]);
        expect($fixture->run('lease.renew', ['attempt_id' => $attempt['attempt_id']])['accepted'])->toBeFalse();
        expect($fixture->run('job.read', ['job_id' => $job]))->toBe($beforeJob);
        expect($fixture->run('attempts.read', ['job_id' => $job]))->toBe($beforeHistory);
        expect($fixture->run('transport.publications', ['job_id' => $job]))->toBe($beforePublications);
    } finally {
        $fixture->dispose();
    }
});

test('changed retained custody cannot be reconciled or adopted', function (): void {
    $fixture = new FencingFixture();
    try {
        $job = $fixture->run('job.prepare', ['name' => 'tampered-custody'])['job_id'];
        $attempt = $fixture->run('worker.start_paused', ['job_id' => $job]);
        expect($fixture->run('host.try_write', ['attempt_id' => $attempt['attempt_id'], 'kind' => 'custody', 'value' => 'component'])['accepted'])->toBeTrue();
        $fixture->run('host.corrupt_custody', ['job_id' => $job]);
        expect($fixture->run('host.reconcile_custody', ['attempt_id' => $attempt['attempt_id']])['accepted'])->toBeFalse();
        expect($fixture->run('host.try_publish', ['attempt_id' => $attempt['attempt_id'], 'coverage' => 'component'])['accepted'])->toBeFalse();
        expect($fixture->run('host.records', ['job_id' => $job])['results'])->toBe([]);
    } finally {
        $fixture->dispose();
    }
});

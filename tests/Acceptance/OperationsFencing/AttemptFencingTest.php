<?php

declare(strict_types=1);

/*
 * OPS-C03 / OPS-PSC04: independent execution ownership and fencing contracts.
 *
 * These actions describe observable outcomes, not production PHP APIs. A real
 * adapter must use native Jobs, Attempts, Messenger and guarded host operations.
 * Without that driver, each scenario is intentionally INCOMPLETE.
 */

test('concurrent workers cannot acquire two current Attempts for one Job', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'competing-initial-claims'])['job_id'];

    // Both real workers must overlap at the persisted claim boundary.
    $race = $this->fencing->run('worker.compete_for_claim', [
        'job_id' => $jobId,
        'workers' => ['alpha', 'bravo'],
    ]);

    expect($race['observed_competing_processes'])->toBe(2);
    expect($race['entered_executor_attempt_ids'])->toHaveCount(1);

    $state = $this->fencing->run('attempts.read', ['job_id' => $jobId]);
    expect($state['attempts'])->toHaveCount(1);
    expect($state['current_attempt_id'])->toBe($race['entered_executor_attempt_ids'][0]);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('transport redelivery alone cannot replace a live unexpired Job lease', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'visibility-not-ownership'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);

    $this->fencing->run('transport.expire_delivery', ['job_id' => $jobId]);
    $delivery = $this->fencing->run('worker.try_redelivery', ['job_id' => $jobId, 'worker' => 'bravo']);

    expect($delivery['received_native_envelope'])->toBeTrue();
    expect($delivery['acquired_attempt'])->toBeFalse();
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['current_attempt_id'])
        ->toBe($a['attempt_id']);
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['attempts'])
        ->toHaveCount(1);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('an expired living worker is superseded by a new Attempt on the same delivery generation', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'live-expired-owner'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);

    $expired = $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);
    expect($expired['database_lease_expired'])->toBeTrue();
    expect($expired['original_worker_alive'])->toBeTrue();

    $b = $this->fencing->run('worker.recover', ['job_id' => $jobId, 'worker' => 'bravo']);
    expect($b['job_id'])->toBe($jobId);
    expect($b['attempt_id'])->not->toBe($a['attempt_id']);
    expect($b['delivery_generation'])->toBe($a['delivery_generation']);

    $state = $this->fencing->run('attempts.read', ['job_id' => $jobId]);
    expect($state['current_attempt_id'])->toBe($b['attempt_id']);
    expect($state['attempts'])->toHaveCount(2);
    expect($state['attempts'][0]['outcome'])->toBe('interrupted');

    $renewal = $this->fencing->run('lease.renew', ['attempt_id' => $a['attempt_id']]);
    expect($renewal['accepted'])->toBeFalse();

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('a stale living Attempt cannot write checkpoints custody or authoritative results', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'fence-host-boundaries'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);
    $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);
    $b = $this->fencing->run('worker.recover', ['job_id' => $jobId, 'worker' => 'bravo']);

    foreach (['checkpoint', 'custody', 'result'] as $operation) {
        $stale = $this->fencing->run('host.try_write', [
            'job_id' => $jobId,
            'attempt_id' => $a['attempt_id'],
            'kind' => $operation,
            'value' => 'stale-' . $operation,
        ]);
        expect($stale['accepted'])->toBeFalse();
        expect($stale['reason'])->toBe('stale_attempt');
    }

    $records = $this->fencing->run('host.records', ['job_id' => $jobId]);
    expect($records['checkpoints'])->toBe([]);
    expect($records['custody'])->toBe([]);
    expect($records['results'])->toBe([]);

    $current = $this->fencing->run('host.try_write', [
        'job_id' => $jobId,
        'attempt_id' => $b['attempt_id'],
        'kind' => 'checkpoint',
        'value' => 'current-checkpoint',
    ]);
    expect($current['accepted'])->toBeTrue();
    expect($this->fencing->run('host.records', ['job_id' => $jobId])['checkpoints'])
        ->toHaveCount(1);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('an expired Attempt loses write and completion authority even before another worker claims', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'expiry-without-successor'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);
    $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);

    $write = $this->fencing->run('host.try_write', [
        'job_id' => $jobId,
        'attempt_id' => $a['attempt_id'],
        'kind' => 'checkpoint',
        'value' => 'expired-owner-checkpoint',
    ]);
    $finish = $this->fencing->run('worker.try_complete', ['attempt_id' => $a['attempt_id']]);

    expect($write['accepted'])->toBeFalse();
    expect($finish['accepted'])->toBeFalse();
    expect($this->fencing->run('job.read', ['job_id' => $jobId])['job']['state'])->not->toBe('completed');
    expect($this->fencing->run('host.records', ['job_id' => $jobId])['checkpoints'])->toBe([]);

    $b = $this->fencing->run('worker.recover', ['job_id' => $jobId, 'worker' => 'bravo']);
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['current_attempt_id'])
        ->toBe($b['attempt_id']);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('a renewed live lease prevents takeover past its original deadline', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'long-lived-renewal'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);

    $renewed = $this->fencing->run('lease.renew', ['attempt_id' => $a['attempt_id']]);
    expect($renewed['accepted'])->toBeTrue();
    expect($renewed['new_deadline_later'])->toBeTrue();

    // Use the database clock and a deliberately short test lease; never rewrite
    // lease_until here or confuse broker visibility with Job ownership.
    $clock = $this->fencing->run('lease.await_original_deadline', [
        'attempt_id' => $a['attempt_id'],
        'original_deadline' => $a['lease_expires_at'],
    ]);
    expect($clock['original_deadline_passed'])->toBeTrue();
    expect($clock['renewed_lease_still_valid'])->toBeTrue();

    $this->fencing->run('transport.expire_delivery', ['job_id' => $jobId]);
    $attempt = $this->fencing->run('worker.try_redelivery', ['job_id' => $jobId, 'worker' => 'bravo']);
    expect($attempt['acquired_attempt'])->toBeFalse();
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['current_attempt_id'])
        ->toBe($a['attempt_id']);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('a replacement reconciles durable custody before publishing authoritative completion', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'custody-before-recovery'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);

    $staged = $this->fencing->run('host.try_write', [
        'job_id' => $jobId,
        'attempt_id' => $a['attempt_id'],
        'kind' => 'custody',
        'value' => 'verified-component-1',
    ]);
    expect($staged['accepted'])->toBeTrue();
    expect($this->fencing->run('host.records', ['job_id' => $jobId])['custody'])
        ->toHaveCount(1);

    $crash = $this->fencing->run('worker.crash', ['attempt_id' => $a['attempt_id']]);
    expect($crash['terminated_process'])->toBeTrue();
    $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);

    $b = $this->fencing->run('worker.recover', ['job_id' => $jobId, 'worker' => 'bravo']);
    $premature = $this->fencing->run('host.try_publish', [
        'job_id' => $jobId,
        'attempt_id' => $b['attempt_id'],
        'coverage' => 'verified-component-1',
    ]);
    expect($premature['accepted'])->toBeFalse();
    expect($premature['reason'])->toBe('reconciliation_required');

    $review = $this->fencing->run('host.reconcile_custody', [
        'job_id' => $jobId,
        'attempt_id' => $b['attempt_id'],
    ]);
    expect($review['verified_custody_keys'])->toBe(['verified-component-1']);

    $published = $this->fencing->run('host.try_publish', [
        'job_id' => $jobId,
        'attempt_id' => $b['attempt_id'],
        'coverage' => 'verified-component-1',
    ]);
    expect($published['accepted'])->toBeTrue();

    $state = $this->fencing->run('host.records', ['job_id' => $jobId]);
    expect($state['custody'])->toHaveCount(1);
    expect($state['results'])->toHaveCount(1);
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['current_attempt_id'])
        ->toBe($b['attempt_id']);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

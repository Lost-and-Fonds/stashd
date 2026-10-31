<?php

declare(strict_types=1);

/*
 * OPS-C03 / OPS-PSC04: independent execution ownership and fencing contracts.
 *
 * These actions describe observable outcomes, not production PHP APIs. A real
 * adapter must use native Jobs, Attempts, Ecotone and guarded host operations.
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

    expect($delivery['received_native_message'])->toBeTrue();
    expect($delivery['acquired_attempt'])->toBeFalse();
    expect($delivery['original_worker_alive'])->toBeTrue();
    expect($delivery['database_lease_valid'])->toBeTrue();
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
    expect(array_column($state['attempts'], 'id'))->toContain($a['attempt_id'], $b['attempt_id']);
    expect(array_column($state['attempts'], 'outcome'))->not->toContain('completed', 'failed');

    $renewal = $this->fencing->run('lease.renew', ['attempt_id' => $a['attempt_id']]);
    expect($renewal['accepted'])->toBeFalse();

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('a stale living Attempt cannot win a check-write race at any authoritative host boundary', function (): void {
    foreach (['checkpoint', 'custody', 'result'] as $operation) {
        $jobId = $this->fencing->run('job.prepare', ['name' => 'fence-' . $operation])['job_id'];
        $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);

        // A starts while valid; expiry and B's claim occur before A's guarded mutation.
        $race = $this->fencing->run('host.race_write', [
            'job_id' => $jobId,
            'attempt_id' => $a['attempt_id'],
            'kind' => $operation,
            'value' => 'stale-' . $operation,
            'successor_worker' => 'bravo',
        ]);
        expect($race['write_boundary_reached_while_valid'])->toBeTrue();
        expect($race['original_worker_alive'])->toBeTrue();
        expect($race['successor_claimed_before_write'])->toBeTrue();
        expect($race['accepted'])->toBeFalse();
        expect($race['successor_attempt_id'])->not->toBe($a['attempt_id']);

        $records = $this->fencing->run('host.records', ['job_id' => $jobId]);
        expect($records['checkpoints'])->toBe([]);
        expect($records['custody'])->toBe([]);
        expect($records['results'])->toBe([]);

        $current = $this->fencing->run('host.try_write', [
            'job_id' => $jobId,
            'attempt_id' => $race['successor_attempt_id'],
            'kind' => $operation,
            'value' => 'current-' . $operation,
        ]);
        expect($current['accepted'])->toBeTrue();
        $records = $this->fencing->run('host.records', ['job_id' => $jobId]);
        expect(
            $records[match ($operation) {
                'checkpoint' => 'checkpoints',
                'custody' => 'custody',
                'result' => 'results',
            }],
        )
            ->toHaveCount(1);

        expect($this->fencing->run('worker.try_complete', ['attempt_id' => $a['attempt_id']])['accepted'])->toBeFalse();

        $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
    }
});

test('an expired Attempt loses write and completion authority even before another worker claims', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'expiry-without-successor'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);
    $expired = $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);
    expect($expired['database_lease_expired'])->toBeTrue();
    expect($expired['original_worker_alive'])->toBeTrue();

    foreach (['checkpoint', 'custody', 'result'] as $operation) {
        $write = $this->fencing->run('host.try_write', [
            'job_id' => $jobId,
            'attempt_id' => $a['attempt_id'],
            'kind' => $operation,
            'value' => 'expired-' . $operation,
        ]);
        expect($write['accepted'])->toBeFalse();
    }

    expect($this->fencing->run('lease.renew', ['attempt_id' => $a['attempt_id']])['accepted'])->toBeFalse();
    $finish = $this->fencing->run('worker.try_complete', ['attempt_id' => $a['attempt_id']]);
    expect($finish['accepted'])->toBeFalse();
    expect($this->fencing->run('job.read', ['job_id' => $jobId])['job']['state'])->not->toBe('completed');
    $records = $this->fencing->run('host.records', ['job_id' => $jobId]);
    expect($records['checkpoints'])->toBe([]);
    expect($records['custody'])->toBe([]);
    expect($records['results'])->toBe([]);
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['attempts'])->toHaveCount(1);

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
    expect($renewed['used_database_clock'])->toBeTrue();

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
    expect($attempt['received_native_message'])->toBeTrue();
    expect($attempt['acquired_attempt'])->toBeFalse();
    expect($attempt['database_lease_valid'])->toBeTrue();
    expect($attempt['original_worker_alive'])->toBeTrue();
    expect($this->fencing->run('attempts.read', ['job_id' => $jobId])['attempts'])->toHaveCount(1);

    $write = $this->fencing->run('host.try_write', [
        'job_id' => $jobId,
        'attempt_id' => $a['attempt_id'],
        'kind' => 'checkpoint',
        'value' => 'after-original-deadline',
    ]);
    expect($write['accepted'])->toBeTrue();
    expect($this->fencing->run('host.records', ['job_id' => $jobId])['checkpoints'])->toHaveCount(1);
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
    $retained = $this->fencing->run('host.records', ['job_id' => $jobId])['custody'];
    expect($retained)->toHaveCount(1);

    $crash = $this->fencing->run('worker.crash', ['attempt_id' => $a['attempt_id']]);
    expect($crash['terminated_process'])->toBeTrue();
    $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);

    $b = $this->fencing->run('worker.recover', ['job_id' => $jobId, 'worker' => 'bravo', 'pause_before_reconciliation' => true]);
    $premature = $this->fencing->run('host.try_publish', [
        'job_id' => $jobId,
        'attempt_id' => $b['attempt_id'],
        'coverage' => 'verified-component-1',
    ]);
    expect($premature['accepted'])->toBeFalse();
    expect($this->fencing->run('host.records', ['job_id' => $jobId])['results'])->toBe([]);

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
    expect($state['custody'])->toBe($retained);
    expect($this->fencing->run('job.read', ['job_id' => $jobId])['job']['state'])->toBe('completed');
    expect($state['results'])->toHaveCount(1);

    // Repeated adoption may reject or return the prior result, but cannot publish twice.
    $this->fencing->run('host.try_publish', [
        'job_id' => $jobId,
        'attempt_id' => $b['attempt_id'],
        'coverage' => 'verified-component-1',
    ]);
    expect($this->fencing->run('host.records', ['job_id' => $jobId]))->toBe($state);
    $attempts = $this->fencing->run('attempts.read', ['job_id' => $jobId])['attempts'];
    expect($attempts)->toHaveCount(2);
    expect(array_column($attempts, 'id'))->toContain($a['attempt_id'], $b['attempt_id']);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

test('concurrent renewal and takeover serialize to one authoritative Attempt in either order', function (): void {
    foreach (['renewal', 'takeover'] as $firstMutation) {
        $jobId = $this->fencing->run('job.prepare', ['name' => 'renewal-race-' . $firstMutation])['job_id'];
        $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);
        $before = $this->fencing->run('job.read', ['job_id' => $jobId])['job'];
        $initialHistory = $this->fencing->run('attempts.read', ['job_id' => $jobId]);
        $initialAttempts = array_column($initialHistory['attempts'], null, 'id');
        expect($initialHistory['attempts'])->toHaveCount(1);
        $originalDeadline = new DateTimeImmutable($before['lease_expires_at']);
        expect($before['claim_attempt_id'])->toBe($a['attempt_id']);

        // Choose database ordering with barriers, never by rewriting a lease or choosing a returned winner.
        $race = $this->fencing->run('lease.compete_with_takeover', [
            'job_id' => $jobId,
            'attempt_id' => $a['attempt_id'],
            'successor_worker' => 'bravo',
            'first_mutation' => $firstMutation,
        ]);
        expect($race['observed_competing_processes'])->toBe(2);
        expect($race['overlapping_database_operations_observed'])->toBeTrue();
        expect($race['received_native_message'])->toBeTrue();
        expect($race['original_worker_alive'])->toBeTrue();
        expect($race['renewal_accepted'])->toBe($firstMutation === 'renewal');
        expect($race['takeover_accepted'])->toBe($firstMutation === 'takeover');
        expect($race['authority_snapshots'])->toHaveKeys(['before', 'after_first', 'after_both']);
        foreach ($race['authority_snapshots'] as $snapshot) {
            expect(count($snapshot['authoritative_attempt_ids']))->toBeLessThanOrEqual(1);
        }

        $renewalAt = new DateTimeImmutable($race['renewal_decision_at']);
        $takeoverAt = new DateTimeImmutable($race['takeover_decision_at']);
        expect($takeoverAt >= $originalDeadline)->toBeTrue();
        $job = $this->fencing->run('job.read', ['job_id' => $jobId])['job'];
        $state = $this->fencing->run('attempts.read', ['job_id' => $jobId]);
        expect($job['id'])->toBe($jobId);
        expect($job['state'])->toBe('running');
        expect($job['delivery_generation'])->toBe($before['delivery_generation']);
        expect($job['domain_failures'])->toBe($before['domain_failures']);
        expect($state['database_lease_valid'])->toBeTrue();
        $retained = array_column($state['attempts'], null, 'id');
        expect($retained[$a['attempt_id']]['started_at'])->toBe($initialAttempts[$a['attempt_id']]['started_at']);
        expect(array_column($state['attempts'], 'outcome'))->not->toContain('completed', 'failed');

        if ($firstMutation === 'renewal') {
            expect($renewalAt < $originalDeadline)->toBeTrue();
            expect(new DateTimeImmutable($job['lease_expires_at']) > $originalDeadline)->toBeTrue();
            expect(new DateTimeImmutable($job['lease_expires_at']) > $takeoverAt)->toBeTrue();
            expect($race['successor_attempt_id'])->toBeNull();
            expect($state['attempts'])->toHaveCount(1);
            $owner = $a['attempt_id'];
        } else {
            expect($renewalAt >= $takeoverAt)->toBeTrue();
            expect($race['successor_attempt_id'])->not->toBe($a['attempt_id']);
            expect($race['successor_attempt_id'])->not->toBeNull();
            expect($state['attempts'])->toHaveCount(2);
            expect(array_column($state['attempts'], 'id'))->toContain($a['attempt_id'], $race['successor_attempt_id']);
            $owner = $race['successor_attempt_id'];
            expect(
                $this->fencing->run('host.try_write', [
                    'job_id' => $jobId,
                    'attempt_id' => $a['attempt_id'],
                    'kind' => 'checkpoint',
                    'value' => 'stale-racing-owner',
                ])['accepted'],
            )->toBeFalse();
        }

        expect($job['claim_attempt_id'])->toBe($owner);
        expect($state['current_attempt_id'])->toBe($owner);
        expect($state['authoritative_attempt_ids'])->toBe([$owner]);
        expect(array_column($state['attempts'], 'id'))->toContain($owner);
        expect(
            $this->fencing->run('host.try_write', [
                'job_id' => $jobId,
                'attempt_id' => $owner,
                'kind' => 'checkpoint',
                'value' => 'serialized-owner',
            ])['accepted'],
        )->toBeTrue();
        expect($this->fencing->run('host.records', ['job_id' => $jobId])['checkpoints'])->toHaveCount(1);

        $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
    }
});

test('a real stale handler failure cannot change its successor or publish another retry', function (): void {
    $jobId = $this->fencing->run('job.prepare', ['name' => 'stale-handler-failure'])['job_id'];
    $a = $this->fencing->run('worker.start_paused', ['job_id' => $jobId, 'worker' => 'alpha']);
    $expired = $this->fencing->run('lease.await_expiration', ['attempt_id' => $a['attempt_id']]);
    expect($expired['database_lease_expired'])->toBeTrue();
    expect($expired['original_worker_alive'])->toBeTrue();
    $b = $this->fencing->run('worker.recover', ['job_id' => $jobId, 'worker' => 'bravo']);
    expect($b['attempt_id'])->not->toBe($a['attempt_id']);
    expect($b['delivery_generation'])->toBe($a['delivery_generation']);

    $before = $this->fencing->run('job.read', ['job_id' => $jobId])['job'];
    $history = $this->fencing->run('attempts.read', ['job_id' => $jobId]);
    $attempts = array_column($history['attempts'], null, 'id');
    expect($history['attempts'])->toHaveCount(2);
    expect($history['database_lease_valid'])->toBeTrue();
    expect($history['authoritative_attempt_ids'])->toBe([$b['attempt_id']]);
    expect($before['claim_attempt_id'])->toBe($b['attempt_id']);
    expect($before['state'])->toBe('running');
    $publications = $this->fencing->run('transport.publications', ['job_id' => $jobId])['publications'];

    $failure = $this->fencing->run('worker.fail', [
        'attempt_id' => $a['attempt_id'],
        'message' => 'stale-handler-failure-probe',
    ]);
    expect($failure['original_worker_alive_at_failure'])->toBeTrue();
    expect($failure['handler_error_observed'])->toBeTrue();
    expect($failure['failure_transition_reached'])->toBeTrue();
    expect($failure['job_failure_accepted'])->toBeFalse();
    expect($failure['native_delivery_settled'])->toBeTrue();

    $after = $this->fencing->run('job.read', ['job_id' => $jobId])['job'];
    expect($after)->toBe($before);
    expect($after['domain_failures'])->toBe($before['domain_failures']);
    expect($after['delivery_generation'])->toBe($before['delivery_generation']);
    expect($this->fencing->run('transport.publications', ['job_id' => $jobId])['publications'])->toBe($publications);
    $state = $this->fencing->run('attempts.read', ['job_id' => $jobId]);
    $retained = array_column($state['attempts'], null, 'id');
    expect($state['attempts'])->toHaveCount(2);
    expect(array_column($state['attempts'], 'id'))->toContain($a['attempt_id'], $b['attempt_id']);
    expect($state['current_attempt_id'])->toBe($b['attempt_id']);
    expect($state['authoritative_attempt_ids'])->toBe([$b['attempt_id']]);
    expect($state['database_lease_valid'])->toBeTrue();
    expect($retained[$b['attempt_id']])->toBe($attempts[$b['attempt_id']]);
    expect($retained[$a['attempt_id']]['started_at'])->toBe($attempts[$a['attempt_id']]['started_at']);
    expect($retained[$a['attempt_id']]['outcome'])->not->toBe('completed');

    expect(
        $this->fencing->run('host.try_write', [
            'job_id' => $jobId,
            'attempt_id' => $b['attempt_id'],
            'kind' => 'checkpoint',
            'value' => 'successor-survives-stale-failure',
        ])['accepted'],
    )->toBeTrue();
    expect($this->fencing->run('host.records', ['job_id' => $jobId])['checkpoints'])->toHaveCount(1);

    $this->fencing->run('worker.release_all', ['job_id' => $jobId]);
});

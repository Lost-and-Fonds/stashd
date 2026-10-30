<?php

declare(strict_types=1);

/*
 * OPS-C02 / OPS-PSC03: independent at-least-once delivery contracts.
 *
 * The actions below describe observations and failure controls, not an
 * implementation API. Without a real driver each test is INCOMPLETE.
 */

test('outbox insert rejection rolls back the accepted Job and leaves no delivery intent', function (): void {
    $scope = $this->delivery->run('probe.prepare', ['name' => 'outbox-rollback'])['scope_id'];
    $work = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];

    $this->delivery->run('delivery.arm_fault', ['point' => 'outbox-insert-rejected']);
    $result = $this->delivery->run('work.request_with_expected_failure', ['work' => $work]);

    expect($result['failure_kind'])->toBe('outbox_write_rejected');
    expect($this->delivery->run('jobs.for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['jobs'])
        ->toBe([]);
    expect($this->delivery->run('delivery.outbox_for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([]);

    $this->delivery->run('delivery.release_fault', ['point' => 'outbox-insert-rejected']);
    $jobId = $this->delivery->run('work.request', $work)['job_id'];
    expect($jobId)->toBeString()->not->toBeEmpty();
});

test('killing admission before commit cannot leave a Job without its outbox entry', function (): void {
    $scope = $this->delivery->run('probe.prepare', ['name' => 'crash-before-commit'])['scope_id'];
    $work = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];

    $boundary = $this->delivery->run('delivery.run_to_boundary', [
        'boundary' => 'admission-before-commit',
        'work' => $work,
    ]);
    expect($boundary['reached'])->toBeTrue();

    $crash = $this->delivery->run('delivery.kill_at_boundary', ['boundary' => 'admission-before-commit']);
    expect($crash['terminated_process'])->toBeTrue();

    expect($this->delivery->run('jobs.for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['jobs'])
        ->toBe([]);
    expect($this->delivery->run('delivery.outbox_for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([]);

    // A real subsequent request can still be admitted; the aborted transaction
    // must not have permanently reserved its canonical Work identity.
    $jobId = $this->delivery->run('work.request', $work)['job_id'];
    expect($jobId)->toBeString()->not->toBeEmpty();
});

test('committed Job intent survives a process restart before the relay runs', function (): void {
    $scope = $this->delivery->run('probe.prepare', ['name' => 'crash-after-commit'])['scope_id'];
    $work = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];
    $jobId = $this->delivery->run('work.request', $work)['job_id'];

    $job = $this->delivery->run('jobs.read', ['job_id' => $jobId])['job'];
    expect($job['state'])->toBe('queued');
    expect($this->delivery->run('delivery.outbox_for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toContain($jobId);
    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([]);

    $this->delivery->run('delivery.restart', ['stop' => 'all-relays-and-workers']);
    $this->delivery->run('delivery.run_until_settled');

    expect($this->delivery->run('jobs.read', ['job_id' => $jobId])['job']['state'])
        ->toBe('completed');
    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$jobId]);
});

test('unavailable forwarding destination retains recoverable intent without inventing Job success', function (): void {
    $scope = $this->delivery->run('probe.prepare', ['name' => 'relay-destination-down'])['scope_id'];
    $work = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];
    $jobId = $this->delivery->run('work.request', $work)['job_id'];

    $this->delivery->run('delivery.arm_fault', ['point' => 'forward-target-unavailable']);
    $attempt = $this->delivery->run('delivery.relay_once');

    expect($attempt['outcome'])->toBe('target_unavailable');
    expect($this->delivery->run('jobs.read', ['job_id' => $jobId])['job']['state'])
        ->toBe('queued');
    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([]);
    expect($this->delivery->run('delivery.recoverable_intents', ['job_id' => $jobId])['job_ids'])
        ->toContain($jobId);

    $this->delivery->run('delivery.release_fault', ['point' => 'forward-target-unavailable']);
    $this->delivery->run('delivery.restart', ['stop' => 'all-relays-and-workers']);
    $this->delivery->run('delivery.retry_stalled_forwarding', ['job_id' => $jobId]);
    $this->delivery->run('delivery.run_until_settled');

    expect($this->delivery->run('jobs.read', ['job_id' => $jobId])['job']['state'])
        ->toBe('completed');
    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$jobId]);
});

test('relay crash after forwarding but before acknowledgment is safe to replay', function (): void {
    $scope = $this->delivery->run('probe.prepare', ['name' => 'relay-ack-crash'])['scope_id'];
    $work = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];
    $jobId = $this->delivery->run('work.request', $work)['job_id'];

    $boundary = $this->delivery->run('delivery.run_to_boundary', [
        'boundary' => 'relay-forwarded-before-ack',
        'job_id' => $jobId,
    ]);
    expect($boundary['reached'])->toBeTrue();
    expect($this->delivery->run('delivery.forwarded_for_job', ['job_id' => $jobId])['job_ids'])
        ->toContain($jobId);
    expect($this->delivery->run('delivery.outbox_for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toContain($jobId);

    $crash = $this->delivery->run('delivery.kill_at_boundary', ['boundary' => 'relay-forwarded-before-ack']);
    expect($crash['terminated_process'])->toBeTrue();
    $this->delivery->run('delivery.restart', ['stop' => 'all-relays-and-workers']);
    $this->delivery->run('delivery.expire_visibility', ['job_id' => $jobId, 'transport' => 'outbox']);
    $this->delivery->run('delivery.run_until_settled');

    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$jobId]);
    $job = $this->delivery->run('jobs.read', ['job_id' => $jobId])['job'];
    expect($job['state'])->toBe('completed')->and($job['attempt_count'])->toBe(1);
});

test('completed Job redelivery after lost worker acknowledgment does not repeat its effect', function (): void {
    $scope = $this->delivery->run('probe.prepare', ['name' => 'worker-ack-crash'])['scope_id'];
    $work = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];
    $jobId = $this->delivery->run('work.request', $work)['job_id'];

    $boundary = $this->delivery->run('delivery.run_to_boundary', [
        'boundary' => 'worker-completed-before-ack',
        'job_id' => $jobId,
    ]);
    expect($boundary['reached'])->toBeTrue();
    expect($this->delivery->run('jobs.read', ['job_id' => $jobId])['job']['state'])
        ->toBe('completed');
    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$jobId]);

    $crash = $this->delivery->run('delivery.kill_at_boundary', ['boundary' => 'worker-completed-before-ack']);
    expect($crash['terminated_process'])->toBeTrue();
    $this->delivery->run('delivery.restart', ['stop' => 'all-relays-and-workers']);
    $this->delivery->run('delivery.expire_visibility', ['job_id' => $jobId, 'transport' => 'jobs']);
    $this->delivery->run('delivery.run_until_settled');

    expect($this->delivery->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$jobId]);
    expect($this->delivery->run('jobs.read', ['job_id' => $jobId])['job']['attempt_count'])
        ->toBe(1);
});

test('a changed forwarding order does not determine the authoritative Job outcome', function (): void {
    $firstScope = $this->delivery->run('probe.prepare', ['name' => 'first-outbox-job'])['scope_id'];
    $secondScope = $this->delivery->run('probe.prepare', ['name' => 'second-outbox-job'])['scope_id'];
    $first = $this->delivery->run('work.request', [
        'scope_id' => $firstScope,
        'mode' => 'refresh',
        'observation' => 'revision-1',
        'priority' => 'routine',
    ])['job_id'];
    $second = $this->delivery->run('work.request', [
        'scope_id' => $secondScope,
        'mode' => 'refresh',
        'observation' => 'revision-1',
        'priority' => 'routine',
    ])['job_id'];

    // Control the actual transport relay order, not the order of this response.
    $result = $this->delivery->run('delivery.forward_in_order', ['job_ids' => [$second, $first]]);
    expect($result['observed_forwarded_job_ids'])->toBe([$second, $first]);
    $this->delivery->run('delivery.run_until_settled');

    expect($this->delivery->run('probe.effects', ['scope_id' => $firstScope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$first]);
    expect($this->delivery->run('probe.effects', ['scope_id' => $secondScope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$second]);
    expect($this->delivery->run('jobs.read', ['job_id' => $first])['job']['state'])
        ->toBe('completed');
    expect($this->delivery->run('jobs.read', ['job_id' => $second])['job']['state'])
        ->toBe('completed');
});

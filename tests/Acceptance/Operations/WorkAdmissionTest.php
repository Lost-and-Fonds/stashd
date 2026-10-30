<?php

declare(strict_types=1);

test('concurrent equivalent requests admit one durable Job', function (): void {
    $scope = $this->operations->run('probe.prepare', ['name' => 'same-work'])['scope_id'];
    $request = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];

    // Independent processes must race at the real admission boundary.
    $result = $this->operations->run('work.request_concurrently', [
        'requests' => array_fill(0, 8, $request),
    ]);

    expect($result['receipts'])->toHaveCount(8);
    $ids = array_unique(array_column($result['receipts'], 'job_id'));
    expect($ids)->toHaveCount(1);
    $jobId = (string) reset($ids);

    $jobs = $this->operations->run('jobs.for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['jobs'];
    expect($jobs)
        ->toHaveCount(1)
        ->and($jobs[0]['id'])
        ->toBe($jobId)
        ->and($jobs[0]['terminal'])
        ->toBeFalse();

    $this->operations->run('work.execute_all');
    $effects = $this->operations->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh']);
    expect($effects['job_ids'])
        ->toBe([$jobId])
        ->and($this->operations->run('jobs.read', ['job_id' => $jobId])['job']['state'])
        ->toBe('completed');
});

test('refresh and complete modes of one work scope remain distinct Work', function (): void {
    $scope = $this->operations->run('probe.prepare', ['name' => 'two-discovery-modes'])['scope_id'];
    $refresh = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];
    $complete = ['scope_id' => $scope, 'mode' => 'complete', 'observation' => 'revision-1', 'priority' => 'routine'];

    $result = $this->operations->run('work.request_concurrently', [
        'requests' => [$refresh, $complete, $refresh, $complete],
    ]);

    $ids = array_column($result['receipts'], 'job_id');
    expect($ids)
        ->toHaveCount(4)
        ->and($ids[0])
        ->toBe($ids[2])
        ->and($ids[1])
        ->toBe($ids[3])
        ->and($ids[0])
        ->not->toBe($ids[1]);

    expect($this->operations->run('jobs.for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['jobs'])
        ->toHaveCount(1)
        ->and($this->operations->run('jobs.for_work', ['scope_id' => $scope, 'mode' => 'complete'])['jobs'])
        ->toHaveCount(1);
});

test('a higher-priority equivalent request promotes one queued Job without duplicate execution', function (): void {
    $otherScope = $this->operations->run('probe.prepare', ['name' => 'older-routine-work'])['scope_id'];
    $targetScope = $this->operations->run('probe.prepare', ['name' => 'promoted-work'])['scope_id'];
    $other = ['scope_id' => $otherScope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];
    $target = ['scope_id' => $targetScope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];

    $olderJob = $this->operations->run('work.request', $other)['job_id'];
    $targetJob = $this->operations->run('work.request', $target)['job_id'];
    $promoted = $this->operations->run('work.request', [...$target, 'priority' => 'interactive'])['job_id'];
    $repeated = $this->operations->run('work.request', [...$target, 'priority' => 'interactive'])['job_id'];
    $lowerPriorityRepeat = $this->operations->run('work.request', $target)['job_id'];

    expect($targetJob)
        ->not
        ->toBe($olderJob)
        ->and($promoted)
        ->toBe($targetJob)
        ->and($repeated)
        ->toBe($targetJob)
        ->and($lowerPriorityRepeat)
        ->toBe($targetJob);

    $job = $this->operations->run('jobs.read', ['job_id' => $targetJob])['job'];
    expect($job['desired_priority'])->toBe('interactive');

    // The worker must not run the earlier routine Job ahead of promoted work.
    $next = $this->operations->run('work.execute_next');
    expect($next['job_id'])->toBe($targetJob);

    $this->operations->run('work.execute_all');
    expect($this->operations->run('probe.effects', ['scope_id' => $targetScope, 'mode' => 'refresh'])['job_ids'])
        ->toBe([$targetJob]);
    $finished = $this->operations->run('jobs.read', ['job_id' => $targetJob])['job'];
    expect($finished['state'])->toBe('completed')->and($finished['attempt_count'])->toBe(1);
});

test('a later legitimate request creates a new Job without erasing the completed one', function (): void {
    $scope = $this->operations->run('probe.prepare', ['name' => 'repeated-refresh'])['scope_id'];
    $request = ['scope_id' => $scope, 'mode' => 'refresh', 'observation' => 'revision-1', 'priority' => 'routine'];

    $first = $this->operations->run('work.request', $request)['job_id'];
    $this->operations->run('work.execute_all');
    expect($this->operations->run('jobs.read', ['job_id' => $first])['job']['state'])->toBe('completed');

    $second = $this->operations->run('work.request', [...$request, 'observation' => 'revision-2'])['job_id'];
    expect($second)->not->toBe($first);
    $this->operations->run('work.execute_all');
    expect($this->operations->run('jobs.read', ['job_id' => $second])['job']['state'])->toBe('completed');

    $history = $this->operations->run('jobs.for_work', ['scope_id' => $scope, 'mode' => 'refresh'])['jobs'];
    $historyIds = array_column($history, 'id');
    $expectedIds = [$first, $second];
    sort($historyIds);
    sort($expectedIds);
    expect($historyIds)->toBe($expectedIds);
    expect(array_column($history, 'terminal'))->toBe([true, true]);
    $effectIds = $this->operations->run('probe.effects', ['scope_id' => $scope, 'mode' => 'refresh'])['job_ids'];
    sort($effectIds);
    expect($effectIds)->toBe($expectedIds);
});

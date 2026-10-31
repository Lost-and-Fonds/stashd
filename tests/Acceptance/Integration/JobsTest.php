<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

test('keyed Jobs are admitted once and completed through native workers', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $one = $f->start('admit', ['key' => 'key', 'payload' => 'hello']);
        $two = $f->start('admit', ['key' => 'key', 'payload' => 'hello']);
        $id = $f->finish($one)['id'];
        expect($f->finish($two)['id'])->toBe($id);
        expect($f->run('admit', ['key' => 'key', 'payload' => 'hello'])['id'])->toBe($id);
        expect($f->count('enqueue'))->toBe(1);
        $f->run('publish', ['id' => $id, 'generation' => 0]);
        $f->run('consume');
        $f->run('handle', ['id' => $id]);
        expect($f->rows('SELECT status, attempts FROM jobs'))->toBe([['status' => 'completed', 'attempts' => 1]]);
        expect($f->rows('SELECT outcome FROM job_attempts'))->toBe([['outcome' => 'completed']]);
        $failure = $f->start('admit', ['key' => 'key', 'payload' => 'different'])->wait();
        expect($failure->exitCode)->toBe(1);
        expect($failure->errorOutput)->toContain('different work');
        $f->database->exec('ALTER TABLE enqueue ADD CONSTRAINT reject_invitation CHECK (false)');
        $failure = $f->start('admit', ['key' => 'rollback', 'payload' => 'hello'])->wait();
        expect($failure->exitCode)->toBe(1);
        expect($failure->errorOutput)->toContain('23514');
        expect($f->count('jobs'))->toBe(1);
    } finally {
        $f->dispose();
    }
});

test('completion database failure preserves the handler failure budget and recovers', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $id = $f->run('request')['id'];
        $f->database->exec("ALTER TABLE jobs ADD CONSTRAINT reject_completion CHECK (status <> 'completed')");
        $f->run('consume');
        expect($f->rows('SELECT status, failures, generation FROM jobs'))->toBe([['status' => 'running', 'failures' => 0, 'generation' => 0]]);
        expect($f->rows('SELECT ended_at FROM job_attempts'))->toBe([['ended_at' => null]]);
        expect($f->count('probe_effects'))->toBe(1);
        $f->database->exec('ALTER TABLE jobs DROP CONSTRAINT reject_completion');
        $f->expire();
        $f->run('consume');
        expect($f->rows('SELECT status, failures FROM jobs'))->toBe([['status' => 'completed', 'failures' => 0]]);
        expect($f->rows('SELECT outcome FROM job_attempts ORDER BY started_at'))->toBe([['outcome' => 'interrupted'], ['outcome' => 'completed']]);
        // The first effect preceded failed completion; arbitrary effects can repeat in this window.
        expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id], ['job_id' => $id]]);
    } finally {
        $f->dispose();
    }
});

test('duplicate old deliveries cannot consume retry attempts', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $id = $f->run('request', ['observation' => 'domain-fail'])['id'];
        $f->run('publish', ['id' => $id, 'generation' => 0]);
        $f->run('consume');
        expect($f->rows('SELECT status, attempts, failures, generation FROM jobs'))->toBe([['status' => 'queued', 'attempts' => 1, 'failures' => 1, 'generation' => 1]]);
        expect($f->count('enqueue'))->toBe(1);
        $f->expire();
        $f->run('consume');
        expect($f->rows('SELECT failures FROM jobs'))->toBe([['failures' => 2]]);
        expect($f->count('job_attempts'))->toBe(2);
        expect($f->count('probe_effects'))->toBe(0);
    } finally {
        $f->dispose();
    }
});

test('expired claims recover when a failed domain retry enqueue rolls back', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $id = $f->run('request', ['observation' => 'domain-fail'])['id'];
        $f->database->exec('ALTER TABLE enqueue ADD CONSTRAINT reject_retry CHECK (false) NOT VALID');
        $failure = $f->start('handle', ['id' => $id])->wait();
        expect($failure->exitCode)->toBe(1);
        expect($failure->errorOutput)->toContain('23514');
        expect($f->rows('SELECT status, failures, generation FROM jobs'))->toBe([['status' => 'running', 'failures' => 0, 'generation' => 0]]);
        expect($f->count('enqueue'))->toBe(1);
        $f->database->exec('ALTER TABLE enqueue DROP CONSTRAINT reject_retry');
        for ($i = 0; $i < 3; $i++) {
            $f->expire();
            $f->run('consume');
        }

        expect($f->rows('SELECT status, attempts, failures FROM jobs'))->toBe([['status' => 'failed', 'attempts' => 4, 'failures' => 3]]);
        expect($f->rows('SELECT outcome FROM job_attempts ORDER BY started_at'))->toBe([
            ['outcome' => 'interrupted'],
            ['outcome' => 'failed'],
            ['outcome' => 'failed'],
            ['outcome' => 'failed'],
        ]);
        expect($f->count('probe_effects'))->toBe(0);
        expect($f->count('enqueue'))->toBe(0);
    } finally {
        $f->dispose();
    }
});

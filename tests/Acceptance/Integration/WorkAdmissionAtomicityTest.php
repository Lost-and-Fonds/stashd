<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

test('Work admission and promotion roll back when their native invitation write fails', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $f->database->exec('ALTER TABLE enqueue ADD CONSTRAINT reject_invitation CHECK (false)');
        $failure = $f->start('request')->wait();
        expect($failure->exitCode)->toBe(1);
        expect($failure->errorOutput)->toContain('23514');
        expect($f->count('jobs'))->toBe(0);
        $f->database->exec('ALTER TABLE enqueue DROP CONSTRAINT reject_invitation');
        $id = $f->run('request')['id'];
        $f->database->exec('ALTER TABLE enqueue ADD CONSTRAINT reject_promotion CHECK (false) NOT VALID');
        $failure = $f->start('request', ['priority' => 'interactive'])->wait();
        expect($failure->exitCode)->toBe(1);
        expect($failure->errorOutput)->toContain('23514');
        expect($f->rows('SELECT id, desired_priority, generation FROM jobs'))->toBe([['id' => $id, 'desired_priority' => 'routine', 'generation' => 0]]);
        expect($f->count('enqueue'))->toBe(1);
    } finally {
        $f->dispose();
    }
});

test('running Work coalesces a new observation and priority without restarting its Attempt', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $claim = 'existing-worker';
        $id = $f->run('request', ['observation' => 'revision-1'])['id'];
        $f->database->exec("UPDATE jobs SET status = 'running', claim_token = 'existing-worker', lease_until = clock_timestamp() + interval '60 seconds'");
        expect($f->run('request', ['observation' => 'revision-2', 'priority' => 'interactive'])['id'])->toBe($id);
        expect($f->rows('SELECT payload, desired_priority, generation, claim_token FROM jobs'))->toBe([[
            'payload' => 'revision-1',
            'desired_priority' => 'interactive',
            'generation' => 0,
            'claim_token' => $claim,
        ]]);
        expect($f->count('enqueue'))->toBe(1);
        expect($f->count('job_attempts'))->toBe(0);
    } finally {
        $f->dispose();
    }
});

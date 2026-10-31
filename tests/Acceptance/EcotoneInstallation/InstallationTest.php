<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

test('a fresh installation preserves intent across reprovisioning without Messenger schema', function (): void {
    $f = new NativeDeliveryFixture(initialize: false);
    try {
        expect($f->rows('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'))->toBe([]);
        $f->run('install', ['without_messenger' => true]);
        $id = $f->run('request', ['without_messenger' => true])['id'];
        $f->run('consume', ['without_messenger' => true]);
        expect($f->rows('SELECT id, status FROM jobs'))->toBe([['id' => $id, 'status' => 'completed']]);
        expect($f->rows('SELECT job_id, outcome FROM job_attempts'))->toBe([['job_id' => $id, 'outcome' => 'completed']]);
        expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
        expect($f->count('enqueue'))->toBe(0);
        $second = $f->run('request', ['observation' => 'second', 'without_messenger' => true])['id'];
        expect($second)->not->toBe($id);
        $pending = $f->rows('SELECT id, body FROM enqueue');
        expect($pending)->toHaveCount(1);
        $f->run('install', ['provision_only' => true, 'without_messenger' => true]);
        expect($f->rows('SELECT id, body FROM enqueue'))->toBe($pending);
        $f->run('consume', ['without_messenger' => true]);
        expect($f->count('job_attempts'))->toBe(2);
        expect($f->rows('SELECT job_id FROM probe_effects ORDER BY sequence'))->toBe([['job_id' => $id], ['job_id' => $second]]);
        expect($f->rows('SELECT id, status FROM jobs ORDER BY id'))->toBe([['id' => $id, 'status' => 'completed'], ['id' => $second, 'status' => 'completed']]);
        expect($f->count('enqueue'))->toBe(0);
        expect($f->database->query("SELECT to_regclass('messenger_messages')")->fetchColumn())->toBeNull();
    } finally {
        $f->dispose();
    }
});

foreach (['commit', 'rollback', 'inner-failure'] as $mode) {
    test("nested Tempest admission preserves outer {$mode} boundaries", function () use ($mode): void {
        $f = new NativeDeliveryFixture();
        try {
            $result = $f->run('nested', ['mode' => $mode]);
            expect($result['before'])->toBe(['mutations' => 0, 'jobs' => 0, 'invitations' => 0]);
            expect($result['same_transaction'])->toBeTrue();
            expect($result['outer_active'])->toBeTrue();
            expect($result['writes_in_outer'])->toBe($mode === 'inner-failure' ? 0 : 2);
            expect($result['inner_error'])->toBe($mode === 'inner-failure' ? '23514' : null);
            $expected = match ($mode) {
                'commit' => ['mutations' => 1, 'jobs' => 1, 'invitations' => 1],
                'rollback' => ['mutations' => 0, 'jobs' => 0, 'invitations' => 0],
                'inner-failure' => ['mutations' => 2, 'jobs' => 0, 'invitations' => 0],
            };
            expect($result['after'])->toBe($expected);
        } finally {
            $f->dispose();
        }
    });
}

test('native startup commands provision idempotently and consume in a fresh CLI', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $admitted = $f->run('console', ['arguments' => ['job:admit', 'cli-proof', 'hello']]);
        expect($admitted['code'])->toBe(0, $admitted['error']);
        $id = json_decode(trim($admitted['output']), true, flags: JSON_THROW_ON_ERROR)['id'];
        $pending = $f->rows('SELECT id, body FROM enqueue');
        expect($pending)->toHaveCount(1);
        $setup = $f->run('console', ['arguments' => ['ecotone:migration:database:setup', '--initialize']]);
        expect($setup['code'])->toBe(0, $setup['error']);
        expect($f->rows('SELECT id, body FROM enqueue'))->toBe($pending);
        $worker = $f->run('console', ['arguments' => ['ecotone:run', 'jobs', '--finishWhenNoMessages', '--memoryLimit=256', '--executionTimeLimit=300000']]);
        expect($worker['code'])->toBe(0, $worker['error']);
        expect($f->rows('SELECT id, status FROM jobs'))->toBe([['id' => $id, 'status' => 'completed']]);
        expect($f->rows('SELECT job_id, outcome FROM job_attempts'))->toBe([['job_id' => $id, 'outcome' => 'completed']]);
        expect($f->count('enqueue'))->toBe(0);
    } finally {
        $f->dispose();
    }
});

test('production messaging caches retain definitions without sharing fixture connections', function (): void {
    $first = new NativeDeliveryFixture();
    $second = null;
    try {
        $second = new NativeDeliveryFixture();
        $one = $first->run('request', ['environment' => 'production'])['id'];
        expect($second->count('jobs'))->toBe(0);
        $two = $second->run('request', ['environment' => 'production'])['id'];
        expect($first->rows('SELECT id FROM jobs'))->toBe([['id' => $one]]);
        expect($second->rows('SELECT id FROM jobs'))->toBe([['id' => $two]]);
        $first->run('consume', ['environment' => 'production']);
        $second->run('consume', ['environment' => 'production']);
        expect($first->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $one]]);
        expect($second->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $two]]);
        expect($first->count('job_attempts'))->toBe(1);
        expect($second->count('job_attempts'))->toBe(1);
        expect($first->count('enqueue'))->toBe(0);
        expect($second->count('enqueue'))->toBe(0);
    } finally {
        $second?->dispose();
        $first->dispose();
    }
});

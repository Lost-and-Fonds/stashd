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

<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

test('native delivery reordering cannot change either authoritative Job outcome', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $first = $f->run('request')['id'];
        $scope = $f->database->query('SELECT gen_random_uuid()')->fetchColumn();
        $f->database->prepare('INSERT INTO probe_scopes VALUES (?, ?)')->execute([$scope, 'delivered-first']);
        $second = $f->run('request', ['scope' => $scope, 'priority' => 'interactive'])['id'];
        $f->run('consume');
        expect($f->rows('SELECT job_id FROM probe_effects ORDER BY sequence'))->toBe([['job_id' => $second], ['job_id' => $first]]);
        expect($f->rows('SELECT id, status FROM jobs ORDER BY id'))->toBe([['id' => $first, 'status' => 'completed'], ['id' => $second, 'status' => 'completed']]);
        expect($f->rows('SELECT job_id, outcome FROM job_attempts ORDER BY started_at'))->toBe([
            ['job_id' => $second, 'outcome' => 'completed'],
            ['job_id' => $first, 'outcome' => 'completed'],
        ]);
        expect($f->count('enqueue'))->toBe(0);
    } finally {
        $f->dispose();
    }
});

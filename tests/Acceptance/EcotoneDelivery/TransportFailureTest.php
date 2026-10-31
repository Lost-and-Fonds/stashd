<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

test('a failed busy invitation and failed native retry retain recoverable delivery', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $id = $f->run('request')['id'];
        $owner = $f->start('consume', ['boundary' => 'claimed']);
        $f->paused($owner);
        $f->run('publish', ['id' => $id, 'generation' => 0]);
        $pending = $f->rows('SELECT id, body FROM enqueue ORDER BY id');
        $f->database->exec(
            "CREATE FUNCTION reject_recovery() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''native invitation rejected'' USING ERRCODE = ''23514''; END'",
        );
        $f->database->exec('CREATE TRIGGER reject_recovery BEFORE INSERT ON enqueue FOR EACH ROW EXECUTE FUNCTION reject_recovery()');
        $result = $f->start('consume', ['limit' => 1])->wait();
        expect($result->exitCode)->toBeIn([0, 1]);
        if ($result->exitCode === 1) {
            expect($result->errorOutput)->toContain('23514');
        }

        expect((int) $f->database->query('SELECT count(*) FROM enqueue WHERE delivery_id IS NOT NULL')->fetchColumn())->toBe(2);
        expect($f->rows('SELECT id, body FROM enqueue ORDER BY id'))->toBe($pending);
        expect($f->rows('SELECT status, failures FROM jobs'))->toBe([['status' => 'running', 'failures' => 0]]);
        expect($f->count('job_attempts'))->toBe(1);
        expect($f->count('probe_effects'))->toBe(0);
        $f->kill($owner);
        $f->database->exec('DROP TRIGGER reject_recovery ON enqueue');
        $f->expire();
        $f->run('consume');
        expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
        expect($f->rows('SELECT outcome FROM job_attempts ORDER BY started_at'))->toBe([['outcome' => 'interrupted'], ['outcome' => 'completed']]);
        expect($f->rows('SELECT status, failures FROM jobs'))->toBe([['status' => 'completed', 'failures' => 0]]);
        expect($f->count('enqueue'))->toBe(0);
    } finally {
        $f->dispose();
    }
});

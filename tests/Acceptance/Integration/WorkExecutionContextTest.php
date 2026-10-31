<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

test('executors receive the persisted claim metadata for each Attempt of one Job', function (): void {
    $f = new NativeDeliveryFixture();
    try {
        $database = $f->database;
        $database->exec(
            'CREATE TABLE context_observations (job_id UUID, attempt_id UUID, generation INTEGER, lease_expires_at TIMESTAMPTZ, claim_token TEXT, persisted_generation INTEGER, persisted_lease TIMESTAMPTZ)',
        );
        $id = $f->run('request')['id'];
        $f->run('consume', ['executor' => 'context']);
        $f->expire();
        $f->run('consume', ['executor' => 'context']);
        $observations = $database
            ->query(
                'SELECT job_id, attempt_id, generation, attempt_id::text = claim_token AND generation = persisted_generation AND lease_expires_at = persisted_lease AS matches_claim FROM context_observations ORDER BY generation',
            )
            ->fetchAll(PDO::FETCH_ASSOC);
        $attempts = $database->query('SELECT id, outcome FROM job_attempts ORDER BY started_at')->fetchAll(PDO::FETCH_ASSOC);
        expect($observations)
            ->toHaveCount(2)
            ->and(array_column($observations, 'job_id'))
            ->toBe([$id, $id])
            ->and(array_column($observations, 'generation'))
            ->toBe([0, 1])
            ->and(array_column($observations, 'matches_claim'))
            ->toBe([true, true])
            ->and(array_column($observations, 'attempt_id'))
            ->toBe(array_column($attempts, 'id'))
            ->and($observations[0]['attempt_id'])
            ->not
            ->toBe($observations[1]['attempt_id'])
            ->and(array_column($attempts, 'outcome'))
            ->toBe(['failed', 'completed'])
            ->and($database->query('SELECT status FROM jobs')->fetchColumn())
            ->toBe('completed');
    } finally {
        $f->dispose();
    }
});

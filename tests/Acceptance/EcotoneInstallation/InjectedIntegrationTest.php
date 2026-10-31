<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

foreach (['commit', 'rollback', 'inner-failure'] as $mode) {
    test("the injected official adapter preserves nested {$mode} boundaries", function () use ($mode): void {
        $f = new NativeDeliveryFixture();
        try {
            $result = $f->run('injected-nested', ['mode' => $mode]);
            expect($result['official_diagnostic']['connection_class'])->toBe(\Ecotone\Api\Tempest\DbalBackedConnection::class);
            expect($result['official_diagnostic']['messenger_installed'])->toBeFalse();
            expect($result['before'])->toBe(['mutations' => 0, 'jobs' => 0, 'invitations' => 0]);
            expect($result['same_transaction'])->toBeTrue();
            expect($result['outer_active'])->toBeTrue();
            expect($result['inner_error'])->toBe($mode === 'inner-failure' ? '23514' : null, $result['inner_error_message'] ?? '');
            expect($result['writes_in_outer'])->toBe($mode === 'inner-failure' ? 0 : 2);
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

foreach (['database-first', 'messaging-first'] as $order) {
    test("the injected official adapter survives reconnect and a fresh consumer with {$order}", function () use ($order): void {
        $f = new NativeDeliveryFixture();
        try {
            $result = $f->run('injected-lifecycle', ['order' => $order, 'private_schema' => true]);
            expect($result['same_owner'])->toBeTrue();
            expect($result['same_transaction'])->toBeTrue();
            expect($result['reconnected'])->toBeTrue();
            expect($result['schema_before'])->toBe($result['schema_after']);
            expect($result['schema_before'])->toStartWith('stashd_tx_test_')->toEndWith('_injected');
            expect($result['persistent'])->toBeFalse();
            expect($result['rejected'])->toBe(['tempest-reconnect' => true, 'dbal-close' => true]);
            expect($result['invisible'])->toBe(0);
            expect($result['rolled_back'])->toBe(0);
            expect($result['committed'])->toBe(1);
            expect($result['dbal_level'])->toBe(0);
            expect($result['effect_count'])->toBe(0);
            expect($f->run('injected-consume', ['private_schema' => true])['effects'])->toBe(['committed']);
            expect((int) $f->database->query('SELECT count(*) FROM "' . $result['schema_after'] . '".enqueue')->fetchColumn())->toBe(0);
        } finally {
            $f->dispose();
        }
    });
}

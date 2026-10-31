<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

foreach (['commit', 'rollback', 'inner-failure'] as $mode) {
    test("the official adapter preserves nested {$mode} boundaries", function () use ($mode): void {
        $f = new NativeDeliveryFixture();
        try {
            $result = $f->run('official-nested', ['mode' => $mode]);
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

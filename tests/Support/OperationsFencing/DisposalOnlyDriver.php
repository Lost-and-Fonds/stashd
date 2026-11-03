<?php

declare(strict_types=1);

namespace Tests\Support\OperationsFencing;

use RuntimeException;

/**
 * Exercise fixture disposal failures without allocating workers or implementing authority.
 */
final class DisposalOnlyDriver implements FencingDriver
{
    public static bool $rejectDisposal = false;

    /**
     * Return the workspace or fail disposal so its recovery evidence can be checked.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments): array
    {
        if ($action === 'fixture.dispose' && self::$rejectDisposal) {
            throw new RuntimeException('Controlled cleanup failure.');
        }

        if (! in_array($action, ['fixture.dispose', 'fixture.path'], true)) {
            throw new RuntimeException('Disposal-only adapter cannot execute authority contracts.');
        }

        return ['workspace' => $arguments['workspace']];
    }
}

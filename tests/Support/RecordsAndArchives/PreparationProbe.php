<?php

namespace Tests\Support\RecordsAndArchives;

use PHPUnit\Framework\Assert;

/**
 * Observe real per-candidate authority without implementing preparation or publication.
 *
 * Children use production hooks and private logs. Unsealed copies may progress in
 * parallel; only those private preparing files are excluded from contention snapshots.
 */
final class PreparationProbe
{
    /**
     * Require the selected completed boundary while the child remains alive and paused.
     *
     * @param array{process: resource, prefix: string, boundary: string} $child
     */
    public static function paused(array $child, string $boundary): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($child['prefix'] . '.paused') && microtime(true) < $deadline) {
            Assert::assertTrue(proc_get_status($child['process'])['running'], 'Child exited before ' . $boundary . ': ' . (string) file_get_contents($child['prefix'] . '.err'));
            usleep(10_000);
        }
        Assert::assertFileExists($child['prefix'] . '.paused', 'Missing candidate boundary: ' . $boundary);
        Assert::assertSame($boundary, file_get_contents($child['prefix'] . '.paused'));
    }

    /**
     * Reap any still-owned children after failures without affecting completed processes.
     *
     * @param list<array{process: resource, prefix: string, boundary: string}> $children
     */
    public static function cancel(array $children): void
    {
        foreach ($children as $child) {
            if (is_resource($child['process'])) {
                proc_terminate($child['process'], 9);
                proc_close($child['process']);
            }
        }
    }

    /**
     * Hold an actual candidate owner until a second claimant proves a denied flock claim.
     *
     * Unsealed custody copying is allowed; sealed candidates and OCFL remain unchanged
     * while the owner is paused. Release or kill/reap the owner before the contender.
     *
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $contender
     * @return list<array>
     */
    public static function contend(string $workspace, array $owner, array $contender, bool $killOwner = false): array
    {
        $children = [];
        try {
            $a = TransactionProbe::start($workspace, $owner, pause: 'candidate-authority-held');
            $children[] = $a;
            self::paused($a, 'candidate-authority-held');
            $before = array_filter(TransactionProbe::snapshot($workspace . '/vault'), static fn (string $path): bool => ! str_contains($path, '.preparing-'), ARRAY_FILTER_USE_KEY);
            $b = TransactionProbe::start($workspace, $contender, pause: 'candidate-authority-contended');
            $children[] = $b;
            self::paused($b, 'candidate-authority-contended');
            Assert::assertStringNotContainsString('candidate-authority-held', (string) file_get_contents($b['prefix'] . '.events'));
            Assert::assertSame('', file_get_contents($a['prefix'] . '.out'));
            Assert::assertSame('', file_get_contents($b['prefix'] . '.out'));
            Assert::assertSame($before, array_filter(
                TransactionProbe::snapshot($workspace . '/vault'),
                static fn (string $path): bool => ! str_contains($path, '.preparing-'),
                ARRAY_FILTER_USE_KEY,
            ));
            if ($killOwner) {
                proc_terminate($a['process'], 9);
            } else {
                file_put_contents($a['prefix'] . '.resume', 'resume');
            }
            $first = TransactionProbe::finish($a);
            if ($killOwner) {
                Assert::assertSame(9, $first['signal']);
                $first = ['status' => 'owner-killed'];
            } else {
                Assert::assertSame(0, $first['exit'], $first['err']);
                $first = TransactionProbe::json($a['prefix'] . '.out');
            }
            file_put_contents($b['prefix'] . '.resume', 'resume');
            $second = TransactionProbe::finish($b);
            Assert::assertSame(0, $second['exit'], $second['err']);
            return [$first, TransactionProbe::json($b['prefix'] . '.out')];
        } finally {
            self::cancel($children);
        }
    }
}

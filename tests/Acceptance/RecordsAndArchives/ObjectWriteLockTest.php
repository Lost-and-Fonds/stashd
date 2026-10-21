<?php

declare(strict_types=1);

use App\RecordsAndArchives\Concurrency\LockFactoryInitializer;
use App\RecordsAndArchives\Concurrency\ObjectWriteLock;
use League\Uri\Urn;
use Symfony\Component\Lock\LockFactory;
use Tempest\Container\GenericContainer;

function lockFactory(): LockFactory
{
    return new LockFactoryInitializer()->initialize(new GenericContainer());
}

it('excludes another process for the same Object URN while allowing unrelated locks', function () {
    $objectId = Urn::fromString('urn:uuid:123e4567-e89b-12d3-a456-426614174100');
    $otherObjectId = Urn::fromString('urn:uuid:123e4567-e89b-12d3-a456-426614174101');
    $resource = 'stashd:object-write:' . $objectId->toString();
    $factory = lockFactory();
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            '-r',
            'require getcwd() . "/vendor/autoload.php";'
                . '$id = \League\Uri\Urn::fromString($argv[1]);'
                . '$container = new \Tempest\Container\GenericContainer();'
                . '$factory = (new \App\RecordsAndArchives\Concurrency\LockFactoryInitializer())->initialize($container);'
                . '(new \App\RecordsAndArchives\Concurrency\ObjectWriteLock($factory))->exclusiveWrite($id, function () { echo "READY\\n"; flush(); fgets(STDIN); });',
            $objectId->toString(),
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 3),
    );

    expect(is_resource($process))->toBeTrue();

    try {
        $ready = false;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $write = $except = [];
            $remaining = max(0, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);

            if ($selected === false || $selected === 0) {
                continue;
            }

            $ready = trim((string) fgets($pipes[1])) === 'READY';
            break;
        }

        expect($ready)->toBeTrue();

        $sameObject = $factory->createLock($resource, ttl: null);
        $unrelated = $factory->createLock('stashd:object-write:' . $otherObjectId->toString(), ttl: null);
        $unprefixed = $factory->createLock($objectId->toString(), ttl: null);

        try {
            expect($sameObject->acquire(false))->toBeFalse();
            expect($unrelated->acquire(false))->toBeTrue();
            expect($unprefixed->acquire(false))->toBeTrue();
        } finally {
            $sameObject->release();
            $unrelated->release();
            $unprefixed->release();
        }
    } finally {
        fwrite($pipes[0], "RELEASE\n");
        fclose($pipes[0]);

        $deadline = microtime(true) + 2;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
});

it('releases an Object write lock when its callback throws', function () {
    $objectId = Urn::fromString('urn:uuid:123e4567-e89b-12d3-a456-426614174102');
    $factory = lockFactory();

    expect(fn () => new ObjectWriteLock($factory)->exclusiveWrite($objectId, function () {
        throw new RuntimeException('archive failed');
    }))
        ->toThrow(RuntimeException::class, 'archive failed');

    $lock = $factory->createLock('stashd:object-write:' . $objectId->toString(), ttl: null);
    expect($lock->acquire(false))->toBeTrue();
    $lock->release();
});

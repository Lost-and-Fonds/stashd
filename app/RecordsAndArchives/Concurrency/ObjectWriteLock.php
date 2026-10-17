<?php

namespace App\RecordsAndArchives\Concurrency;

use League\Uri\Urn;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

class ObjectWriteLock
{
    private const RESOURCE_PREFIX = 'stashd:object-write:';

    private readonly LockFactory $lockFactory;

    public function __construct(?LockFactory $lockFactory = null)
    {
        $this->lockFactory = $lockFactory ?? new LockFactory(new FlockStore());
    }

    public function exclusiveWrite(Urn $objectId, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock(
            resource: self::RESOURCE_PREFIX . $objectId->toString(),
            ttl: null,
        );

        if (! $lock->acquire(true)) {
            throw new RuntimeException('Unable to acquire lock');
        }

        try {
            $result = $callback($objectId);
        } finally {
            $lock->release();
        }

        return $result;
    }
}

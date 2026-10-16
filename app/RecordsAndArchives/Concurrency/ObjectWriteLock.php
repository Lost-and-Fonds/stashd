<?php

namespace App\RecordsAndArchives\Concurrency;

use League\Uri\Urn;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;

class ObjectWriteLock
{
    function __construct(
        private readonly LockFactory $lockFactory
    ) {}

    public function exclusiveWrite(Urn $objectId, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock(
            resource: $objectId->toString(),
            ttl: null
        );

        if (!$lock->acquire(true)) {
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
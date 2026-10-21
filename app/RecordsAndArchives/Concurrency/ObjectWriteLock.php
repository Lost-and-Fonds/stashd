<?php

namespace App\RecordsAndArchives\Concurrency;

use League\Uri\Urn;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Serialize writes to one Object identity using a Symfony lock store.
 *
 * The default flock store coordinates processes sharing its local lock files.
 * It is not a distributed lock or a transaction across multiple Objects.
 */
class ObjectWriteLock
{
    private const RESOURCE_PREFIX = 'stashd:object-write:';

    private readonly LockFactory $lockFactory;

    /**
     * Accept a lock factory or default to the local filesystem flock store.
     */
    public function __construct(?LockFactory $lockFactory = null)
    {
        $this->lockFactory = $lockFactory ?? new LockFactory(new FlockStore());
    }

    /**
     * Wait for the Object lock and run the callback while holding it.
     *
     * Passes the Object URN to the callback and releases the lock in a finally
     * block. Callback failures propagate after release; acquisition failure
     * raises RuntimeException. The lock has no expiry TTL.
     *
     * @template T
     * @param callable(Urn): T $callback
     * @return T
     */
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

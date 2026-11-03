<?php

declare(strict_types=1);

namespace App\Operations\Authority;

use App\Operations\DTO\ActivityOwnership;
use App\Operations\DTO\AssignmentContext;
use App\Operations\Durable\DurableConnections;
use Closure;
use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Messenger\LockActivityAttemptClaim;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use LogicException;
use PDO;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Tempest\Container\Singleton;
use Throwable;

/**
 * Expose upstream session ownership to the protected domain mutation boundary.
 *
 * No TTL renewal or second exclusion mechanism is introduced. The observer uses
 * the journal connection so loss of the claim session is independently visible.
 */
#[Singleton]
final class SessionActivityClaims implements ActivityAttemptClaimInterface
{
    public ?ActivityOwnership $current = null;
    public ?AssignmentContext $domain = null;
    private LockActivityAttemptClaim $claims;

    /**
     * Keep the upstream claim implementation and PostgreSQL's session lifecycle.
     */
    public function __construct(
        private DurableConnections $connections,
    ) {
        $pdo = $connections->claims->getNativeConnection();
        if (! $pdo instanceof PDO) {
            throw new LogicException('Session claims require pdo_pgsql.');
        }

        $this->claims = new LockActivityAttemptClaim(new LockFactory(new PostgreSqlStore($pdo)));
    }

    /**
     * Retain the exact granted lock until upstream releases the activity episode.
     */
    public function claim(ExecutionId $executionId, string $activityId, int $attempt): ?Closure
    {
        $release = $this->claims->claim($executionId, $activityId, $attempt);
        if ($release === null) {
            return null;
        }

        $session = $this->connections->claims->fetchAssociative('SELECT pid, backend_start::text FROM pg_stat_activity WHERE pid = pg_backend_pid()');
        if ($session === false) {
            $release();
            throw new AuthorityLost('The claim session disappeared.');
        }

        $ownership = new ActivityOwnership((int) $session['pid'], $session['backend_start'], crc32(sprintf('durable-activity-%s-%s-%d', $executionId, $activityId, $attempt)));
        $this->current = $ownership;
        try {
            $this->requireOwned($this->connections->journal);
        } catch (Throwable $error) {
            $release();
            $this->current = null;
            throw $error;
        }

        return function () use ($release): void {
            try {
                $release();
            } catch (Throwable $error) {
                // A dead session already released the lock; do not turn fenced rejection into retry.
                $this->requireOwned($this->connections->journal);
                throw $error;
            } finally {
                $this->current = null;
                $this->domain = null;
            }
        };
    }

    /**
     * Check the exact granted advisory lock using another live database session.
     */
    public function requireOwned(Connection $database): void
    {
        $owner = $this->current ?? throw new AuthorityLost('No current Activity ownership.');
        if (! self::owns($database, $owner)) {
            throw new AuthorityLost('The Activity session no longer owns publication authority.');
        }
    }

    /**
     * Observe the exact granted lock without acquiring it or changing worker state.
     */
    public static function owns(Connection $database, ActivityOwnership $owner): bool
    {
        return $database->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid WHERE l.locktype = 'advisory' AND l.mode = 'ExclusiveLock' AND l.granted AND l.pid = ? AND a.backend_start = ?::timestamptz AND l.classid = 0 AND l.objid = ?::oid AND l.objsubid = 1 AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database()))",
            [$owner->pid, $owner->backendStart, $owner->lockKey],
        ) === true;
    }
}

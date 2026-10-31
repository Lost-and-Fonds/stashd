<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\DTO\ExecutionContext;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Tempest\DbalBackedConnection;
use Tempest\Database\Connection\Connection as TempestConnection;

/**
 * Serialize short authoritative database operations against claim and renewal.
 *
 * Callbacks must use the supplied connection and perform database writes only.
 * No downloads, remote effects, or waiting belong inside this boundary. Expiry
 * is checked again after the callback: expired mutations are rolled back together.
 */
final readonly class AttemptAuthority
{
    private Connection $database;

    /**
     * Share the application's transaction owner rather than opening another session.
     */
    public function __construct(
        TempestConnection $connection,
        private LeaseConfig $lease,
    ) {
        if (! $connection instanceof DbalBackedConnection) {
            throw new \LogicException('Attempt authority requires the shared database connection.');
        }

        $this->database = $connection->database();
    }

    /**
     * Commit a short host mutation only while this persisted Attempt has authority.
     *
     * The final database-clock check is the linearization point. A callback must
     * not commit, change Job ownership, escape this connection, or publish external
     * effects. Results may arrive after expiry without invalidating a committed write.
     *
     * @template T
     * @param callable(Connection): T $mutation
     * @return T
     * @throws AuthorityLost
     */
    public function protect(ExecutionContext $context, callable $mutation): mixed
    {
        return $this->database->transactional(function (Connection $database) use ($context, $mutation): mixed {
            $database->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$context->jobId]);
            $this->requireCurrent($context);
            $result = $mutation($database);
            $this->requireCurrent($context);

            return $result;
        });
    }

    /**
     * Protect a terminal database transition which deliberately clears the claim.
     *
     * The callback must end this Attempt on the supplied connection, without
     * renewal or external effects. A final wall-clock check against the locked
     * lease rolls back both the transition and any retry publication on expiry.
     * Expected revoked authority returns false so native delivery can settle.
     *
     * @param callable(Connection): void $transition
     */
    public function finish(ExecutionContext $context, callable $transition): bool
    {
        try {
            $this->database->transactional(function (Connection $database) use ($context, $transition): void {
                $deadline = $database->fetchOne('SELECT lease_until FROM jobs WHERE id = ? FOR UPDATE', [$context->jobId]);
                $this->requireCurrent($context);
                $transition($database);
                if (! $database->fetchOne('SELECT clock_timestamp() < ?::timestamptz', [$deadline])) {
                    throw new AuthorityLost('The Attempt expired during its terminal transition.');
                }
            });

            return true;
        } catch (AuthorityLost) {
            return false;
        }
    }

    /**
     * Extend only the current unexpired Attempt, using time observed after locking.
     *
     * Long-running executors call this between bounded units of work. Blocking
     * external tools need a polling process loop so renewal is not starved.
     */
    public function renew(ExecutionContext $context): bool
    {
        return $this->renewal($context)->accepted;
    }

    /**
     * Return database evidence of acceptance or rejection after any row-lock wait.
     *
     * The materialized clock is sampled inside the actual conditional UPDATE,
     * never before waiting for ownership. Rejected renewals write no history.
     */
    public function renewal(ExecutionContext $context): \App\Operations\DTO\LeaseRenewal
    {
        return $this->database->transactional(function (Connection $database) use ($context): \App\Operations\DTO\LeaseRenewal {
            $database->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$context->jobId]);
            $row = $database->fetchAssociative(
                "WITH decision AS MATERIALIZED (SELECT clock_timestamp() AS at), renewed AS (UPDATE jobs j SET lease_until = decision.at + (? * interval '1 millisecond') FROM decision WHERE j.id = ? AND j.claim_token = ? AND j.status = 'running' AND j.lease_until > decision.at AND EXISTS (SELECT 1 FROM job_attempts a WHERE a.id::text = j.claim_token AND a.job_id = j.id AND a.ended_at IS NULL) RETURNING j.lease_until) SELECT decision.at, renewed.lease_until FROM decision LEFT JOIN renewed ON true",
                [$this->lease->milliseconds, $context->jobId, $context->attemptId],
            );
            if ($row === false) {
                throw new \LogicException('The database did not return a renewal decision.');
            }

            return new \App\Operations\DTO\LeaseRenewal(
                $row['lease_until'] !== null,
                new \DateTimeImmutable($row['at']),
                $row['lease_until'] === null ? null : new \DateTimeImmutable($row['lease_until']),
            );
        });
    }

    /**
     * Re-read identity and PostgreSQL wall time inside the locked transaction.
     */
    private function requireCurrent(ExecutionContext $context): void
    {
        if (! $this->database->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM jobs j JOIN job_attempts a ON a.id::text = j.claim_token AND a.job_id = j.id WHERE j.id = ? AND a.id = ? AND j.status = 'running' AND a.ended_at IS NULL AND j.lease_until > clock_timestamp())",
            [$context->jobId, $context->attemptId],
        )) {
            throw new AuthorityLost('The Attempt no longer has execution authority.');
        }
    }
}

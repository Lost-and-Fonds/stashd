<?php

declare(strict_types=1);

namespace App\Operations\Authority;

use App\Identity\Identifier;
use App\Identity\IdentifierType;
use App\Operations\AttemptOutcome;
use App\Operations\DTO\AssignmentContext;
use App\Operations\Durable\DurableConnections;
use App\Operations\Models\Attempt;
use App\Operations\Models\Demand;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Tempest\Container\Singleton;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

/**
 * Serialize short database mutations with domain takeover and session revocation.
 *
 * Callbacks must use the supplied connection, never commit it, and perform no
 * filesystem or remote effects. Validation occurs again after the mutation;
 * revocation during a callback rolls its database writes back.
 */
#[Singleton]
final readonly class PublicationAuthority
{
    /**
     * Separate the publication connection from the journal's fencing transactions.
     */
    public function __construct(
        private DurableConnections $connections,
        private SessionActivityClaims $claims,
    ) {}

    /**
     * Record an actual handler episode while the upstream session claim is held.
     */
    public function begin(string $assignment, string $demand): AssignmentContext
    {
        return $this->connections->application->transactional(function (Connection $db) use ($assignment, $demand): AssignmentContext {
            $db->fetchOne('SELECT uuid FROM operation_demands WHERE uuid = ? FOR UPDATE', [$demand]);
            $this->claims->requireOwned($db);
            $owner = $this->claims->current ?? throw new AuthorityLost('The claim disappeared.');
            $now = new DateTimeImmutable();
            query(Attempt::class)
                ->update(outcome: AttemptOutcome::Interrupted, ended_at: $now)
                ->where('demand_id', $demand)
                ->where('ended_at IS NULL')
                ->execute();
            $attempt = new Attempt(
                new PrimaryKey(Identifier::create(IdentifierType::Attempt)->uuidFor(IdentifierType::Attempt)),
                $assignment,
                $demand,
                $owner->pid,
                $owner->backendStart,
                $owner->lockKey,
                AttemptOutcome::Running,
                $now,
            );
            query($attempt)->insert($attempt)->execute();
            query(Demand::class)
                ->update(current_attempt: (string) $attempt->uuid)
                ->where('uuid', $demand)
                ->execute();
            $this->claims->requireOwned($db);

            return $this->claims->domain = new AssignmentContext(
                Identifier::fromUuid(IdentifierType::Assignment, $assignment),
                Identifier::fromUuid(IdentifierType::Demand, $demand),
                $attempt->id,
            );
        });
    }

    /**
     * Establish current authority inside the actual mutation transaction.
     *
     * @template T
     * @param callable(Connection): T $mutation
     * @return T
     */
    public function protect(AssignmentContext $context, callable $mutation): mixed
    {
        return $this->protectOn($this->connections->application, $context, $mutation);
    }

    /**
     * Fence activity journal writes on the journal connection's own short transaction.
     */
    public function journal(callable $append): void
    {
        $context = $this->claims->domain;
        if ($context === null) {
            $append();

            return;
        }

        $this->protectOn($this->connections->journal, $context, $append);
    }

    /**
     * Check before and after the callback while holding the demand's authority row.
     *
     * The final ownership observation is the linearization point. A successor's
     * token replacement waits for this transaction. This does not fence other stores.
     *
     * @template T
     * @param callable(Connection): T $mutation
     * @return T
     */
    private function protectOn(Connection $database, AssignmentContext $context, callable $mutation): mixed
    {
        if ((string) $this->claims->domain?->attemptId !== (string) $context->attemptId) {
            throw new AuthorityLost('Context does not belong to the current Activity.');
        }

        return $database->transactional(function (Connection $db) use ($context, $mutation): mixed {
            $token = $db->fetchOne('SELECT current_attempt FROM operation_demands WHERE uuid = ? AND assignment_id = ? FOR UPDATE', [
                $context->demandId->uuidFor(IdentifierType::Demand),
                $context->assignmentId->uuidFor(IdentifierType::Assignment),
            ]);
            if (! is_string($token) || ! hash_equals($token, $context->attemptId->uuidFor(IdentifierType::Attempt))) {
                throw new AuthorityLost('A successor replaced this Attempt.');
            }

            $this->claims->requireOwned($db);
            $result = $mutation($db);
            $this->claims->requireOwned($db);

            return $result;
        });
    }
}

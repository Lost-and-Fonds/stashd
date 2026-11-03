<?php

declare(strict_types=1);

use App\Operations\Authority\AuthorityLost;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\Authority\SessionActivityClaims;
use App\Operations\Models\Checkpoint;
use App\Operations\Worker;
use Gplanchat\Durable\ExecutionId;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\Durable\DepartmentJob;

/**
 * C03, session-ownership specification.
 *
 * This architecture does not use renewable TTL leases. The invariant is that a
 * superseded Attempt or an execution without its actual PostgreSQL session
 * claim may not publish protected domain effects.
 */
beforeEach(function (): void {
    $this->department = new DepartmentFixture();
});

afterEach(function (): void {
    if (isset($this->releaseClaim)) {
        ($this->releaseClaim)();
    }

    if (isset($this->department)) {
        $this->department->dispose();
    }
});

/**
 * Establish a real session claim for focused publication-boundary checks.
 *
 * @return array{\App\Operations\DTO\AssignmentContext, Closure}
 */
function c03_context(DepartmentFixture $fixture): array
{
    $assignment = $fixture->container->get(Worker::class)->do(new DepartmentJob('c03-fencing'));
    $db = $fixture->connections->application;
    $uuid = $assignment->id->uuidFor(\App\Operations\Assignment::class);
    $demand = $db->fetchOne('SELECT uuid FROM operation_demands WHERE assignment_id = ?', [$uuid]);

    $release = $fixture->container->get(SessionActivityClaims::class)->claim(
        ExecutionId::fromString($uuid),
        'c03-fencing',
        1,
    );
    expect($release)->toBeInstanceOf(Closure::class);

    return [$fixture->container->get(PublicationAuthority::class)->begin($uuid, $demand), $release];
}

test('C03: protected publication requires the live ownership claim', function (): void {
    [$context, $this->releaseClaim] = c03_context($this->department);

    Checkpoint::record($this->department->container->get(PublicationAuthority::class), $context, 'accepted');

    $db = $this->department->connections->application;
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_checkpoints'))->toBe(1);

    // Dropping the *actual session claim*, without changing the domain token,
    // must prohibit publication. A nominal open DB transaction is insufficient.
    $this->department->connections->claims->executeQuery('SELECT pg_advisory_unlock_all()');
    expect(fn () => Checkpoint::record(
        $this->department->container->get(PublicationAuthority::class),
        $context,
        'stale',
    ))
        ->toThrow(AuthorityLost::class);

    expect((int) $db->fetchOne('SELECT count(*) FROM operation_checkpoints'))->toBe(1);
});

test('C03: clearing the current Attempt token revokes publication', function (): void {
    [$context, $this->releaseClaim] = c03_context($this->department);

    $db = $this->department->connections->application;
    $demand = $context->demandId->uuidFor(\App\Operations\Models\Demand::class);

    // Revoke the token directly; independent-process tests prove actual takeover.
    $db->executeStatement('UPDATE operation_demands SET current_attempt = NULL WHERE uuid = ?', [$demand]);

    expect(fn () => Checkpoint::record(
        $this->department->container->get(PublicationAuthority::class),
        $context,
        'stale-after-takeover',
    ))
        ->toThrow(AuthorityLost::class);

    expect((int) $db->fetchOne('SELECT count(*) FROM operation_checkpoints'))->toBe(0);
});

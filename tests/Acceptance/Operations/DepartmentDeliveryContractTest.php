<?php

declare(strict_types=1);

use App\Operations\AssignmentState;
use App\Operations\Priority;
use App\Operations\Worker;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\Durable\DepartmentJob;

/**
 * C02, revised for the production Messenger + Durable department.
 *
 * These assertions use the real Tempest Worker, database transaction, outbox,
 * Durable consumers and an independently persisted handler effect. They do not
 * invoke the historical Operations engine.
 */
beforeEach(function (): void {
    $this->department = new DepartmentFixture();
    $this->worker = $this->department->container->get(Worker::class);
});

afterEach(function (): void {
    if (isset($this->department)) {
        $this->department->dispose();
    }
});

test('C02: equivalent admission retains one Assignment and executes one effect', function (): void {
    $first = $this->worker->do(new DepartmentJob('c02-equivalent'));
    $second = $this->worker->do(new DepartmentJob('c02-equivalent'));

    expect((string) $second->id)->toBe((string) $first->id);

    $db = $this->department->connections->application;
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(1);
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(1);

    $this->department->runtime->drain();

    expect($this->worker->status($first->id)->state)->toBe(AssignmentState::Completed);
    expect((int) $db->fetchOne('SELECT count(*) FROM effects'))->toBe(1);
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(1);
});

test('C02: new observations coalesce while retaining distinct immutable demand', function (): void {
    $first = $this->worker->do(new DepartmentJob('c02-observations', 'revision-one'));
    $second = $this->worker->do(new DepartmentJob('c02-observations', 'revision-two'), Priority::Interactive);

    expect((string) $second->id)->toBe((string) $first->id);
    expect($second->priority)->toBe(Priority::Interactive);

    $db = $this->department->connections->application;
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(1);
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(2);
    expect((int) $db->fetchOne('SELECT count(DISTINCT observation_key) FROM operation_demands'))->toBe(2);

    $this->department->runtime->drain();

    expect($this->worker->status($first->id)->state)->toBe(AssignmentState::Completed);
    expect($db->fetchFirstColumn('SELECT value FROM effects'))->toEqualCanonicalizing([
        'c02-observations:refresh:revision-one',
        'c02-observations:refresh:revision-two',
    ]);
});

test('C02: the same observation cannot be reused for different retained Job input', function (): void {
    $first = $this->worker->do(new DepartmentJob('c02-conflict', 'revision-one', 'refresh'));

    // The same key and observation with different serialized input is ambiguous.
    // custodyPath is part of the Job payload, not its key or observation.
    expect(fn () => $this->worker->do(new DepartmentJob('c02-conflict', 'revision-one', 'refresh', '/not-used'), Priority::Interactive))
        ->toThrow(InvalidArgumentException::class);

    $db = $this->department->connections->application;
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(1);
    expect((int) $db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(1);
    expect((string) $this->worker->status($first->id)->id)->toBe((string) $first->id);
    expect($this->worker->status($first->id)->priority)->toBe(Priority::Routine);
    $this->department->runtime->drain();
    expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['c02-conflict:refresh:revision-one']);
});

test('C02: a terminal Assignment is not silently reused for new work', function (): void {
    $first = $this->worker->do(new DepartmentJob('c02-terminal'));
    $this->department->runtime->drain();
    expect($this->worker->status($first->id)->state)->toBe(AssignmentState::Completed);

    $next = $this->worker->do(new DepartmentJob('c02-terminal'));
    expect((string) $next->id)->not->toBe((string) $first->id);
    expect((int) $this->department->connections->application->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(2);
    $this->department->runtime->drain();
    expect($this->worker->status($next->id)->state)->toBe(AssignmentState::Completed);
    expect((int) $this->department->connections->application->fetchOne('SELECT count(*) FROM effects'))->toBe(2);
});

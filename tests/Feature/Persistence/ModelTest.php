<?php

declare(strict_types=1);

use App\Identity\Identifier;
use App\Operations\Assignment;
use App\Operations\AssignmentState;
use App\Operations\Authority\AuthorityLost;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\Authority\SessionActivityClaims;
use App\Operations\Models\Attempt;
use App\Operations\Models\Checkpoint;
use App\Operations\Models\Demand;
use App\Operations\Models\Result;
use App\Operations\Persistence\ApplicationConnection;
use App\Operations\Persistence\UpgradeCheckpoints;
use App\Operations\Worker;
use App\Persistence\Model;
use App\Persistence\MutationAuthority;
use Gplanchat\Durable\ExecutionId;
use Tempest\Clock\Clock;
use Tempest\Clock\MockClock;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Exceptions\QueryWasInvalid;
use Tempest\Database\Migrations\CreateMigrationsTable;
use Tempest\Database\Migrations\MigrationManager;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Validation\Exceptions\ValidationFailed;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\Durable\DepartmentJob;
use Tests\Support\Persistence\AllowedRecord;
use Tests\Support\Persistence\FencedRecord;
use Tests\Support\Persistence\ForbiddenRecord;
use Tests\Support\Persistence\InsertOnlyRecord;
use Tests\Support\Persistence\RenamedRecord;

use function Tempest\Database\query;

beforeEach(function (): void {
    $this->fixture = new DepartmentFixture();
    $this->clock = new MockClock('2026-11-01T12:00:00Z');
    $this->fixture->container->singleton(Clock::class, $this->clock);
    new Query(
        new CreateTableStatement('foundation_records')
            ->uuid('uuid')
            ->text('value')
            ->datetime('started_at', nullable: true)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->raw("CHECK (value <> 'rejected')"),
    )
        ->execute();
});

afterEach(function (): void {
    if (isset($this->release)) {
        ($this->release)();
    }

    if (isset($this->fixture)) {
        $this->fixture->dispose();
    }
});

/**
 * Establish real session ownership and an Attempt using ordinary admission and authority services.
 *
 * @return array{App\Operations\DTO\AssignmentContext, Closure}
 */
function foundation_execution(DepartmentFixture $fixture): array
{
    $assignment = $fixture->container->get(Worker::class)->do(new DepartmentJob('foundation'));
    $uuid = $assignment->id->uuidFor(Assignment::class);
    $demand = $fixture->connections->application->fetchOne('SELECT uuid FROM operation_demands WHERE assignment_id = ?', [$uuid]);
    $release = $fixture->container->get(SessionActivityClaims::class)->claim(ExecutionId::fromString($uuid), 'foundation', 1);
    expect($release)->toBeInstanceOf(Closure::class);

    return [$fixture->container->get(PublicationAuthority::class)->begin($uuid, $demand), $release];
}

test('identity is lazy UUIDv7 and remains stable before insertion and after hydration', function (): void {
    $row = new AllowedRecord('first');
    expect(isset($row->uuid))->toBeFalse();
    $id = $row->id;
    expect($id->uuid::class)->toBe(Symfony\Component\Uid\UuidV7::class);
    expect($row->id)->toEqual($id);
    expect((string) $row->uuid)->toBe($id->uuidFor(AllowedRecord::class));
    expect($row->insert())->toBe($row);
    expect(AllowedRecord::get($id)->id)->toEqual($id);
    expect(AllowedRecord::get($row->uuid)->id)->toEqual($id);
    expect(AllowedRecord::select()->where('value', 'first')->first()->id)->toEqual($id);

    $unobserved = new AllowedRecord('unobserved')->insert();
    expect($unobserved->id->uuidFor(AllowedRecord::class))->toBe((string) $unobserved->uuid);
    expect(AllowedRecord::get((string) $unobserved->uuid)->id)->toEqual($unobserved->id);

    $preassigned = new AllowedRecord('preassigned');
    $preassigned->uuid = new PrimaryKey('018fba1c-020c-7000-8000-000000000001');
    $preassignedId = $preassigned->id;
    $preassigned->insert();
    expect($preassigned->id)->toEqual($preassignedId);
    expect(AllowedRecord::get($preassigned->id)->uuid->value)->toBe($preassigned->uuid->value);
    expect($preassigned->insert(...))->toThrow(QueryWasInvalid::class);
    expect(AllowedRecord::get($preassignedId)->value)->toBe('preassigned');
    expect(fn () => AllowedRecord::get(Identifier::create(Attempt::class)))->toThrow(InvalidArgumentException::class);
    expect(Identifier::fromUuid(Attempt::class, (string) $row->uuid)->namespace)->toBe('attempt');
    expect(Identifier::fromUuid(stdClass::class, (string) $row->uuid)->namespace)->toBe('stdclass');
    $unknown = new /**
     * Anonymous PHP names cannot accidentally become public URN namespaces.
     */ class extends Model {};
    expect(fn () => $unknown->id)->toThrow(InvalidArgumentException::class);
});

test('an identity override survives insertion and hydration under a renamed model', function (): void {
    $row = new AllowedRecord('published');
    $id = $row->id;
    expect($id->namespace)->toBe('item');
    $row->insert();
    $renamed = RenamedRecord::get(Identifier::parse((string) $id));
    expect($renamed->id)->toEqual($id);
    expect($renamed->value)->toBe('published');
    expect($renamed->uuid->value)->toBe($row->uuid->value);
    expect(FencedRecord::get($renamed->uuid)->id->namespace)->toBe('fencedrecord');
    expect(fn () => FencedRecord::get($id))->toThrow(InvalidArgumentException::class);
});

test('failed validation and database writes preserve the instance and persisted timestamps', function (): void {
    $invalid = new AllowedRecord('');
    expect($invalid->insert(...))->toThrow(ValidationFailed::class);
    expect(isset($invalid->uuid))->toBeFalse();
    expect(isset($invalid->created_at))->toBeFalse();
    $rejected = new AllowedRecord('rejected');
    expect($rejected->insert(...))->toThrow(QueryWasInvalid::class);
    expect(isset($rejected->created_at))->toBeFalse();
    expect(AllowedRecord::select()->all())->toHaveCount(0);

    $row = new AllowedRecord('valid')->insert();
    $updated = $row->updated_at;
    $this->clock->plus(60);
    expect(fn () => $row->update(value: 'changed', related: []))->toThrow(LogicException::class, 'Update related models separately.');
    expect(fn () => $row->update(value: ''))->toThrow(ValidationFailed::class);
    expect(fn () => $row->update(value: 'rejected'))->toThrow(QueryWasInvalid::class);
    expect($row->value)->toBe('valid');
    expect($row->updated_at)->toEqual($updated);
    expect($row->refresh()->updated_at)->toEqual($updated);
});

test('Tempest maps persistence timestamps and preserves domain and creation timestamps', function (): void {
    $started = new DateTimeImmutable('2020-01-01T00:00:00Z');
    $row = new AllowedRecord('before', $started)->insert();
    $created = $row->created_at;
    expect($created)->toEqual($this->clock->toPsrClock()->now());
    expect($row->updated_at)->toEqual($created);
    $this->clock->plus(60);
    expect($row->update(value: 'after'))->toBe($row);
    expect($row->created_at)->toEqual($created);
    expect($row->updated_at)->toEqual($this->clock->toPsrClock()->now());
    $row->value = 'unsaved';
    expect($row->refresh())->toBe($row);
    expect($row->value)->toBe('after');
    expect($row->started_at)->toEqual($started);
    expect($row->created_at)->toEqual($created);
    expect($row->updated_at)->toEqual($this->clock->toPsrClock()->now());
    foreach (['uuid' => $row->uuid, 'id' => $row->id, 'created_at' => $created, 'updated_at' => $created] as $field => $value) {
        expect(fn () => $row->update(...[$field => $value]))->toThrow(LogicException::class);
    }

    $row->delete();
    expect(AllowedRecord::get($row->id))->toBeNull();
    expect($row->refresh(...))->toThrow(RuntimeException::class);
});

test('a deleted model cannot report a successful update or delete', function (string $model): void {
    if ($model === FencedRecord::class) {
        [, $this->release] = foundation_execution($this->fixture);
    }

    $row = new $model('retained')->insert();
    $updated = $row->updated_at;
    $model::get($row->uuid)->delete();
    $this->clock->plus(60);
    expect(fn () => $row->update(value: 'missing'))->toThrow(RuntimeException::class, 'The model no longer exists.');
    expect($row->value)->toBe('retained');
    expect($row->updated_at)->toEqual($updated);
    expect($row->delete(...))->toThrow(RuntimeException::class, 'The model no longer exists.');
    expect($model::get($row->uuid))->toBeNull();
})->with([AllowedRecord::class, FencedRecord::class]);

test('Tempest compiled update and delete queries retain their bindings with PostgreSQL returning', function (): void {
    $row = new AllowedRecord('before')->insert();
    $other = new AllowedRecord('untouched')->insert();
    $literal = 'bound value; RETURNING "uuid" -- ?';
    $row->update(value: $literal);
    expect(AllowedRecord::get($row->id)->value)->toBe($literal);
    $row->delete();
    expect(AllowedRecord::get($row->id))->toBeNull();
    expect(AllowedRecord::get($other->id)->value)->toBe('untouched');
});

test('missing attributes and unspecified operations forbid mutation before changing the instance', function (): void {
    $row = new ForbiddenRecord('forbidden');
    expect($row->insert(...))->toThrow(LogicException::class);
    expect(fn () => $row->update(value: 'changed'))->toThrow(LogicException::class);
    expect($row->delete(...))->toThrow(LogicException::class);
    expect(isset($row->uuid))->toBeFalse();
    expect(isset($row->created_at))->toBeFalse();
    expect($row->value)->toBe('forbidden');
    expect(AllowedRecord::select()->all())->toHaveCount(0);

    $insertOnly = new InsertOnlyRecord('retained')->insert();
    expect(fn () => $insertOnly->update(value: 'changed'))->toThrow(LogicException::class);
    expect($insertOnly->delete(...))->toThrow(LogicException::class);
    expect($insertOnly->refresh()->value)->toBe('retained');
});

test('an arbitrary transaction cannot supply missing execution authority', function (): void {
    $db = $this->fixture->connections->application;
    $db->beginTransaction();
    try {
        $row = new FencedRecord('unauthorised');
        expect($row->insert(...))->toThrow(AuthorityLost::class);
        expect(fn () => $row->update(value: 'changed'))->toThrow(AuthorityLost::class);
        expect($row->delete(...))->toThrow(AuthorityLost::class);
        expect(isset($row->uuid))->toBeFalse();
        expect(isset($row->created_at))->toBeFalse();
        expect($db->getTransactionNestingLevel())->toBe(1);
    } finally {
        $db->rollBack();
    }

    expect(AllowedRecord::select()->all())->toHaveCount(0);
});

test('standalone fenced writes require the live Attempt and exact session claim', function (): void {
    [$context, $this->release] = foundation_execution($this->fixture);
    $row = new FencedRecord('authorised')->insert();
    $this->clock->plus(30);
    $row->update(value: 'updated');
    expect($row->refresh()->value)->toBe('updated');
    $deleted = new FencedRecord('delete')->insert();
    $deleted->delete();
    expect(FencedRecord::get($deleted->id))->toBeNull();

    $db = $this->fixture->connections->application;
    $demand = $context->demandId->uuidFor(Demand::class);
    $db->executeStatement('UPDATE operation_demands SET current_attempt = NULL WHERE uuid = ?', [$demand]);
    expect(fn () => new FencedRecord('stale')->insert())->toThrow(AuthorityLost::class);
    expect(fn () => $row->update(value: 'stale'))->toThrow(AuthorityLost::class);
    expect($row->delete(...))->toThrow(AuthorityLost::class);
    expect($row->refresh()->value)->toBe('updated');

    $db->executeStatement('UPDATE operation_demands SET current_attempt = ? WHERE uuid = ?', [$context->attemptId->uuidFor(Attempt::class), $demand]);
    $this->fixture->connections->claims->executeQuery('SELECT pg_advisory_unlock_all()');
    expect(fn () => new FencedRecord('revoked')->insert())->toThrow(AuthorityLost::class);
    expect(fn () => $row->update(value: 'revoked'))->toThrow(AuthorityLost::class);
    expect($row->delete(...))->toThrow(AuthorityLost::class);
    expect(FencedRecord::select()->all())->toHaveCount(1);
});

test('fenced models join the service transaction on the actual shared application connection', function (): void {
    [$context, $this->release] = foundation_execution($this->fixture);
    $db = $this->fixture->connections->application;
    $adapter = $this->fixture->container->get(Connection::class);
    expect($adapter)->toBeInstanceOf(ApplicationConnection::class);
    expect($adapter->database)->toBe($db);
    expect(new Query('SELECT pg_backend_pid() AS pid')->fetchFirst()['pid'])->toBe($db->fetchOne('SELECT pg_backend_pid()'));
    $authority = $this->fixture->container->get(PublicationAuthority::class);
    expect($this->fixture->container->get(MutationAuthority::class))->toBe($authority);
    $authority->protect($context, function () use ($db): void {
        expect($db->getTransactionNestingLevel())->toBe(1);
        new FencedRecord('one')->insert();
        new FencedRecord('two')->insert();
        expect($db->getTransactionNestingLevel())->toBe(1);
        expect(FencedRecord::select()->all())->toHaveCount(2);
        expect($this->fixture->connections->journal->fetchOne('SELECT count(*) FROM foundation_records'))->toBe(0);
    });
    expect($this->fixture->connections->journal->fetchOne('SELECT count(*) FROM foundation_records'))->toBe(2);
    expect($db->isTransactionActive())->toBeFalse();
});

test('coordinated Tempest writes roll back with DBAL writes when the service fails', function (): void {
    [$context, $this->release] = foundation_execution($this->fixture);
    $authority = $this->fixture->container->get(PublicationAuthority::class);
    $db = $this->fixture->connections->application;
    expect(fn () => $authority->protect($context, function () use ($db): void {
        $db->insert('effects', ['assignment' => 'fixture', 'value' => 'rollback']);
        new FencedRecord('one')->insert();
        new FencedRecord('two')->insert();
        throw new RuntimeException('service failed');
    }))
        ->toThrow('service failed');
    expect(FencedRecord::select()->all())->toHaveCount(0);
    expect($db->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
    expect($db->isTransactionActive())->toBeFalse();
});

test('revocation after coordinated model writes rolls back the whole protected transaction', function (): void {
    [$context, $this->release] = foundation_execution($this->fixture);
    $authority = $this->fixture->container->get(PublicationAuthority::class);
    expect(fn () => $authority->protect($context, function (): void {
        new FencedRecord('one')->insert();
        new FencedRecord('two')->insert();
        $this->fixture->connections->claims->executeQuery('SELECT pg_advisory_unlock_all()');
    }))
        ->toThrow(AuthorityLost::class);
    expect(FencedRecord::select()->all())->toHaveCount(0);
});

test('the real handler records a typed timestamped checkpoint through the shared foundation', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'model-checkpoint-');
    file_put_contents($path, 'retained checkpoint input');
    try {
        $worker = $this->fixture->container->get(Worker::class);
        $assignment = $worker->do(new DepartmentJob('model-checkpoint', custodyPath: $path));
        $this->fixture->runtime->drain();
        expect($worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        $rows = Checkpoint::select()->all();
        expect($rows)->toHaveCount(1);
        $row = $rows[0];
        expect($row)->toBeInstanceOf(Model::class);
        expect($row->value)->toBe('staged');
        expect($row->assignment_id)->toBe($assignment->id->uuidFor(Assignment::class));
        expect($row->id->namespace)->toBe('checkpoint');
        expect(Checkpoint::get($row->id)->uuid->value)->toBe($row->uuid->value);
        expect($row->created_at)->toEqual($this->clock->toPsrClock()->now());
        expect($row->updated_at)->toEqual($row->created_at);
        expect(fn () => $row->update(value: 'changed'))->toThrow(LogicException::class);
        expect($row->delete(...))->toThrow(LogicException::class);
        expect($row->insert(...))->toThrow(AuthorityLost::class);
        expect(Checkpoint::get($row->id)->value)->toBe('staged');
    } finally {
        unlink($path);
    }
});

test('checkpoint migration preserves legacy UUIDs and progress values', function (): void {
    $db = $this->fixture->connections->application;
    $db->executeStatement('CREATE SCHEMA legacy_checkpoints');
    $db->executeStatement('SET search_path TO legacy_checkpoints');
    $migrations = $this->fixture->container->get(MigrationManager::class);
    $migrations->executeUp(new CreateMigrationsTable());
    new Query(
        new CreateTableStatement('operation_checkpoints')
            ->uuid()
            ->text('assignment_id')
            ->text('demand_id')
            ->text('attempt_id')
            ->text('value'),
    )
        ->execute();
    $id = Identifier::create(Checkpoint::class);
    $values = [
        'assignment_id' => Identifier::create(Assignment::class)->uuidFor(Assignment::class),
        'demand_id' => Identifier::create(Demand::class)->uuidFor(Demand::class),
        'attempt_id' => Identifier::create(Attempt::class)->uuidFor(Attempt::class),
        'value' => 'retained progress',
    ];
    query('operation_checkpoints')->insert(...['id' => $id->uuidFor(Checkpoint::class), ...$values])->execute();
    $migrations->executeUp(new UpgradeCheckpoints());
    $row = Checkpoint::get($id);
    expect($row->id)->toEqual($id);
    expect($row->value)->toBe($values['value']);
    expect($row->assignment_id)->toBe($values['assignment_id']);
    expect($row->demand_id)->toBe($values['demand_id']);
    expect($row->attempt_id)->toBe($values['attempt_id']);
    expect($row->created_at)->toBeInstanceOf(DateTimeImmutable::class);
    expect($row->updated_at)->toEqual($row->created_at);
    expect(fn () => query('operation_checkpoints')->insert(...['uuid' => (string) Symfony\Component\Uid\Uuid::v7(), ...$values])->execute())->toThrow(QueryWasInvalid::class);
});

test('the real handler records a typed timestamped result through the shared foundation', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'model-result-');
    file_put_contents($path, 'retained result input');
    try {
        $worker = $this->fixture->container->get(Worker::class);
        $assignment = $worker->do(new DepartmentJob('model-result', custodyPath: $path));
        $this->fixture->runtime->drain();
        expect($worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        $rows = Result::select()->all();
        expect($rows)->toHaveCount(1);
        $row = $rows[0];
        expect($row)->toBeInstanceOf(Model::class);
        expect($row->coverage)->toBe('verified');
        expect($row->assignment_id)->toBe($assignment->id->uuidFor(Assignment::class));
        expect($row->id->namespace)->toBe('result');
        expect(Result::get($row->id)->uuid->value)->toBe($row->uuid->value);
        expect($row->created_at)->toEqual($this->clock->toPsrClock()->now());
        expect($row->updated_at)->toEqual($row->created_at);
        expect(fn () => $row->update(coverage: 'changed'))->toThrow(LogicException::class);
        expect($row->delete(...))->toThrow(LogicException::class);
        expect($row->insert(...))->toThrow(AuthorityLost::class);
        expect(Result::get($row->id)->coverage)->toBe('verified');
    } finally {
        unlink($path);
    }
});

test('recording a Result is idempotent and does not complete the Assignment', function (): void {
    [$context, $this->release] = foundation_execution($this->fixture);
    $authority = $this->fixture->container->get(PublicationAuthority::class);
    Result::record($authority, $context, 'verified');
    $row = Result::select()->first();
    $this->clock->plus(60);
    Result::record($authority, $context, 'verified');
    expect(Result::select()->all())->toHaveCount(1);
    expect(Result::get($row->id)->updated_at)->toEqual($row->updated_at);
    expect(fn () => Result::record($authority, $context, 'different'))->toThrow(RuntimeException::class, 'A different result was already recorded.');
    expect(Result::get($row->id)->coverage)->toBe('verified');
    expect($this->fixture->container->get(Worker::class)->status($context->assignmentId)->state)->toBe(AssignmentState::Running);
    expect(Demand::get($context->demandId)->satisfied)->toBeFalse();
});

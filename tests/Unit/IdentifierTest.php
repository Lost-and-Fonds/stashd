<?php

declare(strict_types=1);

use App\Identity\Identifier;
use App\Identity\IdentifierMapper;
use App\Operations\Assignment;
use App\Operations\Models\Attempt;
use App\Operations\Models\Checkpoint;
use App\Operations\Models\Demand;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Support\Identity\HTTP_Attempts;
use Tests\Support\Identity\HTTPAttempts;
use Tests\Support\Persistence\AllowedRecord;
use Tests\Support\Persistence\RenamedRecord;

test('existing and unfamiliar Stashd namespaces round trip without model resolution', function (string $namespace): void {
    $urn = 'urn:stashd:' . $namespace . ':018fba1c-020c-7000-8000-000000000001';
    $id = Identifier::parse($urn);
    expect($id->namespace)->toBe($namespace);
    expect((string) $id)->toBe($urn);
    expect(json_decode(json_encode($id, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($urn);
    $mapper = new IdentifierMapper();
    expect($mapper->serialize($id))->toBe($urn);
    expect($mapper->cast($urn))->toEqual($id);
    expect($mapper->cast($id))->toBe($id);
})->with(['item', 'asset', 'vault', 'assignment', 'attempt', 'demand', 'checkpoint', 'unknown', 'collection-entries2', 'job_results']);

test('concrete class names determine default namespaces without singularising or merging separators', function (string $model, string $namespace): void {
    $id = Identifier::create($model);
    expect($id->namespace)->toBe($namespace);
    expect($id->uuid)->toBeInstanceOf(UuidV7::class);
    expect(Identifier::fromUuid($model, $id->uuidFor($model)))->toEqual($id);
    expect(Identifier::parse((string) $id, $model))->toEqual($id);
})->with([
    [Assignment::class,    'assignment'],
    [Attempt::class,       'attempt'],
    [Demand::class,        'demand'],
    [Checkpoint::class,    'checkpoint'],
    [HTTPAttempts::class,  'httpattempts'],
    [HTTP_Attempts::class, 'http_attempts'],
]);

test('an identity override preserves published identifiers after a model rename', function (): void {
    $id = Identifier::create(AllowedRecord::class);
    expect($id->namespace)->toBe('item');
    expect(Identifier::fromUuid(RenamedRecord::class, $id->uuidFor(RenamedRecord::class)))->toEqual($id);
    expect(Identifier::parse((string) $id, RenamedRecord::class))->toEqual($id);
});

test('URN parsing canonicalises UUID case without changing its namespace', function (): void {
    $id = Identifier::parse('urn:stashd:stable-name:018FBA1C-020C-7000-8000-000000ABCDEF');
    expect((string) $id)->toBe('urn:stashd:stable-name:018fba1c-020c-7000-8000-000000abcdef');
});

test('namespaces have one canonical spelling at construction and parsing boundaries', function (string $namespace): void {
    expect(fn () => new Identifier($namespace, Uuid::v7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => Identifier::parse('urn:stashd:' . $namespace . ':018fba1c-020c-7000-8000-000000000001'))->toThrow(InvalidArgumentException::class);
})->with(['', 'Attempt', '0attempt', '-attempt', '_attempt', 'attempt-', 'attempt__history', 'attempt--history', 'attempt history', 'attempt:history', 'attempt%20history']);

test('Stashd identifiers reject malformed and untyped input', function (string $input): void {
    expect(fn () => Identifier::parse($input))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    '018fba1c-020c-7000-8000-000000000001',
    'urn:other:assignment:018fba1c-020c-7000-8000-000000000001',
    'urn:stashd:assignment:zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz',
    "urn:stashd:assignment:018fba1c-020c-7000-8000-000000000001\n",
]);

test('a domain UUID cannot be substituted for another model namespace', function (): void {
    $attempt = Identifier::create(Attempt::class);
    expect(fn () => Identifier::parse((string) $attempt, Assignment::class))->toThrow(InvalidArgumentException::class);
    expect(fn () => $attempt->uuidFor(Demand::class))->toThrow(InvalidArgumentException::class);
});

test('the identifier mapper rejects values that are neither identifiers nor URNs', function (): void {
    $mapper = new IdentifierMapper();
    foreach ([null, 1, ['namespace' => 'item'], new stdClass()] as $value) {
        expect(fn () => $mapper->cast($value))->toThrow(InvalidArgumentException::class);
        expect(fn () => $mapper->serialize($value))->toThrow(InvalidArgumentException::class);
    }
});

<?php

declare(strict_types=1);

use App\Identity\Identifier;
use App\Identity\IdentifierType;


test('Stashd identifiers round trip every declared namespace', function (IdentifierType $type): void {
    $id = Identifier::create($type);
    expect((string) $id)->toStartWith('urn:stashd:' . $type->value . ':');
    expect(Identifier::parse((string) $id, $type))->toEqual($id);
    expect(Identifier::fromUuid($type, $id->uuidFor($type)))->toEqual($id);
    expect(json_decode(json_encode($id, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe((string) $id);
})->with(IdentifierType::cases());

test('Stashd identifiers reject malformed and untyped input', function (string $input): void {
    expect(fn () => Identifier::parse($input))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'urn:stashd:unknown:018fba1c-020c-7000-8000-000000000001',
    '018fba1c-020c-7000-8000-000000000001',
    'urn:other:assignment:018fba1c-020c-7000-8000-000000000001',
    'urn:stashd:assignment:zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz',
    "urn:stashd:assignment:018fba1c-020c-7000-8000-000000000001\n",
]);

test('an arbitrary domain UUID cannot be substituted for another identifier type', function (): void {
    $attempt = Identifier::create(IdentifierType::Attempt);
    expect(fn () => Identifier::parse((string) $attempt, IdentifierType::Assignment))->toThrow(InvalidArgumentException::class);
    expect(fn () => $attempt->uuidFor(IdentifierType::Demand))->toThrow(InvalidArgumentException::class);
});

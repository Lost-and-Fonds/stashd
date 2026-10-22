<?php

declare(strict_types=1);

use App\RecordsAndArchives\VaultIdentity;
use League\Uri\Exceptions\SyntaxError;

/**
 * Supply the persisted identity shape written by Vault::create(), with explicit overrides.
 *
 * @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function identity_json_fields(array $changes = []): array
{
    return array_replace([
        'format' => 'stashd-vault',
        'version' => 1,
        'identifier' => 'urn:stashd:vault:123e4567-e89b-12d3-a456-426614174100',
        'createdAt' => '2026-10-22T00:00:00.000Z',
    ], $changes);
}

test('identity JSON round trips persisted fields without enforcing format compatibility', function (string $format, int $version): void {
    $fields = identity_json_fields(['format' => $format, 'version' => $version]);
    $identity = VaultIdentity::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));

    expect($identity->format)
        ->toBe($format)
        ->and($identity->version)
        ->toBe($version)
        ->and($identity->identifier->toString())
        ->toBe($fields['identifier'])
        ->and($identity->createdAt->getTimestamp()->getSeconds())
        ->toBe(1_792_627_200);
    expect(json_decode(json_encode($identity, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($fields);
})->with([['stashd-vault', 1], ['future-format', 99]]);

test('identity JSON rejects missing fields before accessing or parsing them', function (string $field): void {
    $fields = identity_json_fields();
    unset($fields[$field]);

    expect(fn () => VaultIdentity::fromJson(json_encode($fields, JSON_THROW_ON_ERROR)))
        ->toThrow(TypeError::class, 'Vault identity must contain');
})->with(['format', 'version', 'identifier', 'createdAt']);

test('identity JSON rejects incorrect field types instead of coercing them', function (array $changes): void {
    expect(fn () => VaultIdentity::fromJson(json_encode(identity_json_fields($changes), JSON_THROW_ON_ERROR)))
        ->toThrow(TypeError::class, 'Vault identity must contain');
})->with([
    [['format' => 1]],
    [['format' => null]],
    [['version' => '1']],
    [['version' => 1.5]],
    [['version' => true]],
    [['identifier' => 123]],
    [['identifier' => []]],
    [['createdAt' => 1_792_627_200]],
    [['createdAt' => null]],
]);

test('identity JSON rejects non-record roots', function (string $json): void {
    expect(fn () => VaultIdentity::fromJson($json))->toThrow(TypeError::class, 'Vault identity must contain');
})->with(['null', '[]', '{}', 'true', '42', '"identity"']);

test('identity JSON retains decoding errors', function (string $json): void {
    expect(fn () => VaultIdentity::fromJson($json))->toThrow(JsonException::class);
})->with(['{', '{"version":}', '{"version":1,}']);

test('identity JSON retains URN and date parsing failures', function (): void {
    expect(fn () => VaultIdentity::fromJson(json_encode(identity_json_fields(['identifier' => 'not a URN']), JSON_THROW_ON_ERROR)))
        ->toThrow(SyntaxError::class);
    expect(fn () => VaultIdentity::fromJson(json_encode(identity_json_fields(['createdAt' => 'not a date']), JSON_THROW_ON_ERROR)))
        ->toThrow(DateMalformedStringException::class);
});

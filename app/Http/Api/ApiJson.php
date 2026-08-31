<?php

declare(strict_types=1);

namespace App\Http\Api;

use Tempest\DateTime\DateTimeInterface;

final class ApiJson
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** @param array<mixed, mixed> $body
     * @return array<string, mixed>
     */
    public static function normalizeRequest(array $body): array
    {
        return self::snakeToCamel($body);
    }

    /** @return array<string, mixed> */
    public static function encode(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return self::camelToSnake($data);
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function snakeToCamel(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = is_string($key) ? self::snakeToCamelKey($key) : (string) $key;
            $normalized[$normalizedKey] = is_array($value) ? self::snakeToCamel($value) : $value;
        }

        return $normalized;
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function camelToSnake(array $data): array
    {
        $encoded = [];

        foreach ($data as $key => $value) {
            $encodedKey = is_string($key) ? self::camelToSnakeKey($key) : (string) $key;

            if (is_array($value)) {
                $encoded[$encodedKey] = array_is_list($value)
                    ? array_map(
                        static fn(mixed $item): mixed => is_array($item) ? self::camelToSnake($item) : self::encodeValue($item),
                        $value,
                    )
                    : self::camelToSnake($value);

                continue;
            }

            $encoded[$encodedKey] = self::encodeValue($value);
        }

        return $encoded;
    }

    private static function encodeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->toRfc3339(useZ: true);
        }

        return $value;
    }

    private static function snakeToCamelKey(string $key): string
    {
        if (! str_contains($key, '_')) {
            return $key;
        }

        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }

    private static function camelToSnakeKey(string $key): string
    {
        return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
    }
}

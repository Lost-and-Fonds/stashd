<?php

declare(strict_types=1);

namespace App\System\Storage;

use InvalidArgumentException;

use function Tempest\Support\str;

/** Centralized path segment sanitizer — prevents traversal and unsafe characters. */
final class PathSanitizer
{
    private const int MAX_SEGMENT_LENGTH = 200;

    public static function sanitizeSegment(string $value): string
    {
        $decoded = rawurldecode($value);
        $segment = str($decoded)->trim()->toString();

        if ($segment === '') {
            throw new InvalidArgumentException('Invalid path segment.');
        }

        if (
            str($segment)->contains('..')
            || str($segment)->contains('/')
            || str($segment)->contains('\\')
            || str($segment)->startsWith('/')
            || preg_match('/^[A-Za-z]:/', $segment) === 1
        ) {
            throw new InvalidArgumentException('Invalid path segment.');
        }

        $sanitized = (string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $segment);
        $sanitized = trim($sanitized, '-.');

        if ($sanitized === '') {
            throw new InvalidArgumentException('Path segment sanitized to empty value.');
        }

        if (strlen($sanitized) > self::MAX_SEGMENT_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_SEGMENT_LENGTH);
            $sanitized = rtrim($sanitized, '-.');
        }

        if ($sanitized === '') {
            throw new InvalidArgumentException('Path segment sanitized to empty value.');
        }

        return $sanitized;
    }

    /** Allows spaces for readable external-library folder and episode names. */
    public static function sanitizeBroadcastSegment(string $value): string
    {
        $decoded = rawurldecode($value);
        $segment = str($decoded)->trim()->toString();

        if ($segment === '') {
            throw new InvalidArgumentException('Invalid path segment.');
        }

        if (
            str($segment)->contains('..')
            || str($segment)->contains('/')
            || str($segment)->contains('\\')
            || str($segment)->startsWith('/')
            || preg_match('/^[A-Za-z]:/', $segment) === 1
        ) {
            throw new InvalidArgumentException('Invalid path segment.');
        }

        $sanitized = (string) preg_replace('/[^a-zA-Z0-9._ -]+/', '-', $segment);
        $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized) ?? '', '-. ');

        if ($sanitized === '') {
            throw new InvalidArgumentException('Path segment sanitized to empty value.');
        }

        if (strlen($sanitized) > self::MAX_SEGMENT_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_SEGMENT_LENGTH);
            $sanitized = rtrim($sanitized, '-. ');
        }

        if ($sanitized === '') {
            throw new InvalidArgumentException('Path segment sanitized to empty value.');
        }

        return $sanitized;
    }
}

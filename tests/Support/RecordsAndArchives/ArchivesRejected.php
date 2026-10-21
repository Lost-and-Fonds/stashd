<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

use RuntimeException;

/**
 * Test-only signal that the real application intentionally rejected a request.
 *
 * Drivers should map expected domain failures to this exception, preserving
 * unexpected infrastructure failures and PHPUnit incomplete-test markers.
 */
final class ArchivesRejected extends RuntimeException {}

<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Reject a revoked execution without turning it into another domain failure.
 */
final class AuthorityLost extends \RuntimeException {}

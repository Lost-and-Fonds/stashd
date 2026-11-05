<?php

declare(strict_types=1);

namespace App\Operations;

use RuntimeException;
use Throwable;

/**
 * Retain both the startup cause and an independent failure to confirm child cleanup.
 */
final class OperationsWorkerStartupException extends RuntimeException
{
    /**
     * Keep the original cause in the exception chain and the cleanup failure separately inspectable.
     */
    public function __construct(
        Throwable $startup,
        public readonly Throwable $cleanupFailure,
    ) {
        parent::__construct('Worker startup failed and cleanup could not be confirmed: ' . $startup->getMessage(), previous: $startup);
    }
}

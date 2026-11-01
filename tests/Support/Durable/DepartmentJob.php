<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use App\Operations\Job;
use App\Operations\Work;

/**
 * Exercise real departmental admission using a small immutable typed request.
 */
final readonly class DepartmentJob implements Job
{
    /**
     * Keep identity and observed demand independently controllable by contracts.
     */
    public function __construct(
        public string $scope,
        public string $observation = 'one',
        public string $mode = 'refresh',
        public ?string $custodyPath = null,
    ) {}

    /**
     * Use a stable operation type while retaining each observation as input.
     */
    public function work(): Work
    {
        return new Work('department.probe', $this->scope, $this->mode, $this->observation);
    }
}

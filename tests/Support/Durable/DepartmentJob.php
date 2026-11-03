<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use App\Operations\Job;

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
     * Encode component boundaries so different scope/mode pairs cannot collide.
     */
    public function key(): string
    {
        return json_encode([$this->scope, $this->mode], JSON_THROW_ON_ERROR);
    }

    public function observation(): string
    {
        return $this->observation;
    }
}

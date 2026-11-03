<?php

declare(strict_types=1);

namespace App\Operations\Durable;

/**
 * Invite a worker to initialize a durably admitted assignment's workflow.
 */
final readonly class StartAssignment
{
    /**
     * Carry only stable domain identity across deployments.
     */
    public function __construct(
        public string $id,
    ) {}
}

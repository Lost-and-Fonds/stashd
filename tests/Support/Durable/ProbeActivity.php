<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Describe the blocking probe using Durable's typed activity contract.
 */
interface ProbeActivity
{
    /**
     * Record one independently observable effect.
     */
    #[AsActivityMethod('spike.effect')]
    public function effect(string $value): string;
}

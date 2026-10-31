<?php

declare(strict_types=1);

namespace Tests\Support\OperationsFencing;

use Tests\TestCase;

/**
 * Create and dispose of one separate OPS-C03 installation per Pest scenario.
 */
abstract class FencingTestCase extends TestCase
{
    protected FencingFixture $fencing;

    /**
     * Prepare an isolated installation without reusing a previous scenario.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fencing = new FencingFixture();
    }

    /**
     * Reap controlled workers and dispose of storage even after failed assertions.
     */
    protected function tearDown(): void
    {
        try {
            $this->fencing->dispose();
        } finally {
            parent::tearDown();
        }
    }
}

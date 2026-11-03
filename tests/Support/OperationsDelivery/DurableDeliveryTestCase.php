<?php

declare(strict_types=1);

namespace Tests\Support\OperationsDelivery;

use Tests\TestCase;

/**
 * Install and dispose of a separate delivery fixture for every acceptance test.
 */
abstract class DurableDeliveryTestCase extends TestCase
{
    protected DurableDeliveryFixture $delivery;

    /**
     * Create private disposable resources before testing a delivery failure.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->delivery = new DurableDeliveryFixture();
    }

    /**
     * Always release workers, database resources, and the private workspace.
     */
    protected function tearDown(): void
    {
        try {
            $this->delivery->dispose();
        } finally {
            parent::tearDown();
        }
    }
}

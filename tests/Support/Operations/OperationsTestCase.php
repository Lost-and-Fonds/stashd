<?php

declare(strict_types=1);

namespace Tests\Support\Operations;

use Tests\TestCase;

/**
 * Gives each Operations acceptance test a fresh, isolated installation.
 */
abstract class OperationsTestCase extends TestCase
{
    protected OperationsFixture $operations;

    /**
     * Prepare the disposable fixture before running the approved contract.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->operations = new OperationsFixture();
    }

    /**
     * Release fixture files after success, failure or an incomplete test.
     */
    protected function tearDown(): void
    {
        try {
            $this->operations->dispose();
        } finally {
            parent::tearDown();
        }
    }
}

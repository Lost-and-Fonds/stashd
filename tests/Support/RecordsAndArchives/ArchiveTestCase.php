<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

use Tests\TestCase;

/**
 * Ensures each acceptance scenario has its own disposable filesystem roots.
 */
abstract class ArchiveTestCase extends TestCase
{
    protected ArchiveFixture $archive;

    /**
     * Prepare a unique fixture before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->archive = new ArchiveFixture();
    }

    /**
     * Clean up after all outcomes, including failed assertions.
     */
    protected function tearDown(): void
    {
        try {
            $this->archive->dispose();
        } finally {
            parent::tearDown();
        }
    }
}

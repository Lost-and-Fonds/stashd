<?php

use Tests\Support\Operations\OperationsTestCase;
use Tests\Support\RecordsAndArchives\ArchiveTestCase;
use Tests\TestCase;

// Acceptance tests receive a disposable workspace. Feature tests do not.
pest()->extend(TestCase::class)->in('Feature');
pest()->extend(ArchiveTestCase::class)->in('Acceptance/RecordsAndArchives');
pest()->extend(OperationsTestCase::class)->in('Acceptance/Operations');

// Use the production boundary by default; an explicit adapter remains available to independent reviewers.
if (! getenv('STASHD_TRANSACTION_TEST_ADAPTER')) {
    putenv('STASHD_TRANSACTION_TEST_ADAPTER=' . __DIR__ . '/Support/RecordsAndArchives/transaction-adapter.php');
}

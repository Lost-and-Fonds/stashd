<?php

use Tests\Support\RecordsAndArchives\ArchiveTestCase;
use Tests\TestCase;

// Acceptance tests receive a disposable workspace. Feature tests do not.
pest()->extend(TestCase::class)->in('Feature');
pest()->extend(ArchiveTestCase::class)->in('Acceptance/RecordsAndArchives');

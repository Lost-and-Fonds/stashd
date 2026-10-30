<?php

declare(strict_types=1);

use App\EventManagement\EventCatalogueAudit;

require dirname(__DIR__) . '/vendor/autoload.php';

$report = (new EventCatalogueAudit())->audit(dirname(__DIR__));
printf("Defined: %d\n", count($report->coverage));
printf("Missing: %d\n", count($report->missingClasses));
printf("Unexpected: %d\n", count($report->unexpectedClasses));
printf("Contract violations: %d\n", count($report->contractViolations));
printf("Emitted (proven): %d\n", count(array_filter($report->coverage, static fn (array $row): bool => $row['emitted'])));
printf("Verified (proven): %d\n", count(array_filter($report->coverage, static fn (array $row): bool => $row['verified'])));
printf("Consumed (proven): %d\n", count(array_filter($report->coverage, static fn (array $row): bool => $row['consumed'])));
foreach ([...$report->missingClasses, ...$report->unexpectedClasses, ...$report->contractViolations] as $issue) {
    fwrite(STDERR, "- {$issue}\n");
}
foreach ($report->coverage as $class => $stages) {
    printf(
        "%s defined=%s emitted=%s verified=%s consumed=%s\n",
        $class,
        $stages['defined'] ? 'yes' : 'unverified',
        $stages['emitted'] ? 'yes' : 'unverified',
        $stages['verified'] ? 'yes' : 'unverified',
        $stages['consumed'] ? 'yes' : 'unverified',
    );
}
exit($report->isComplete() ? 0 : 1);

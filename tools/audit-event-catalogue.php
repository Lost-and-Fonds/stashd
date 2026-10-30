<?php

declare(strict_types=1);

use App\EventManagement\EventCatalogueAudit;

require dirname(__DIR__) . '/vendor/autoload.php';

$report = (new EventCatalogueAudit())->audit(dirname(__DIR__));
printf("Defined: %d\n", count($report->coverage));
printf("Missing: %d\n", count($report->missingClasses));
printf("Unexpected: %d\n", count($report->unexpectedClasses));
printf("Contract violations: %d\n", count($report->contractViolations));
printf("Emitted: %d\n", count(array_filter($report->coverage, static fn (array $row): bool => $row['emitted'])));
printf("Verified: %d\n", count(array_filter($report->coverage, static fn (array $row): bool => $row['verified'])));
printf("Consumed: %d\n", count(array_filter($report->coverage, static fn (array $row): bool => $row['consumed'])));
foreach ([...$report->missingClasses, ...$report->unexpectedClasses, ...$report->contractViolations] as $issue) {
    fwrite(STDERR, "- {$issue}\n");
}
foreach ($report->coverage as $class => $stages) {
    printf(
        "%s defined=%s emitted=%s verified=%s consumed=%s\n",
        $class,
        $stages['defined'] ? 'yes' : 'no',
        $stages['emitted'] ? 'yes' : 'no',
        $stages['verified'] ? 'yes' : 'no',
        $stages['consumed'] ? 'yes' : 'no',
    );
}
exit($report->isComplete() ? 0 : 1);

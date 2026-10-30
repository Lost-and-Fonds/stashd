<?php

declare(strict_types=1);

use App\EventManagement\EventCatalogueAudit;

require dirname(__DIR__) . '/vendor/autoload.php';

$report = (new EventCatalogueAudit())->audit(dirname(__DIR__));
printf("Defined: %d\n", count($report->coverage));
printf("Missing: %d\n", count($report->missingClasses));
printf("Unexpected: %d\n", count($report->unexpectedClasses));
printf("Contract violations: %d\n", count($report->contractViolations));
printf("Emitted: unmeasured (no integration evidence mechanism)\n");
printf("Verified: unmeasured (no integration evidence mechanism)\n");
printf("Consumed: unmeasured (no integration evidence mechanism)\n");
foreach ([...$report->missingClasses, ...$report->unexpectedClasses, ...$report->contractViolations] as $issue) {
    fwrite(STDERR, "- {$issue}\n");
}
foreach ($report->coverage as $class => $stages) {
    printf(
        "%s defined=%s emitted=%s verified=%s consumed=%s\n",
        $class,
        $stages['defined'] ? 'yes' : 'unverified',
        match ($stages['emitted']) { true => 'yes', false => 'no', null => 'unknown' },
        match ($stages['verified']) { true => 'yes', false => 'no', null => 'unknown' },
        match ($stages['consumed']) { true => 'yes', false => 'no', null => 'unknown' },
    );
}
exit($report->isComplete() ? 0 : 1);

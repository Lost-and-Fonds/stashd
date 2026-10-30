<?php

use App\EventManagement\Contracts\ClientEventPublisher;
use App\EventManagement\Contracts\EventHistoryRecorder;
use App\EventManagement\DTO\CapabilityChange;
use App\EventManagement\DTO\ClientEvent;
use App\EventManagement\DTO\DiscoveryCoverage;
use App\EventManagement\DTO\EventMetadata;
use App\EventManagement\DTO\PublicationOutcome;
use App\EventManagement\EventCatalogueAudit;
use App\EventManagement\Events\Acquisition\AcquisitionPartiallyCompleted;
use App\EventManagement\Events\Catalogue\ItemDiscovered;
use App\EventManagement\Events\Catalogue\StashCreated;
use App\EventManagement\Events\Custody\AssetCustodyAcknowledged;
use App\EventManagement\Events\Discovery\DiscoveryRunCompleted;
use App\EventManagement\Events\Enrichment\EnrichmentPlanned;
use App\EventManagement\Events\Execution\JobPauseAccepted;
use App\EventManagement\Events\Incidents\IncidentAcknowledged;
use App\EventManagement\Events\Integrity\FixityCheckFailed;
use App\EventManagement\Events\Preservation\AssetVersionCommitted;
use App\EventManagement\Events\Publication\ItemPublicationAcknowledged;
use App\EventManagement\Events\Replication\ReplicaRegistered;
use App\EventManagement\Events\Scheduling\ScheduledWorkDue;
use App\EventManagement\Events\Security\CredentialUpdated;
use App\EventManagement\Events\Sources\InputCreated;
use App\EventManagement\Events\System\PluginCapabilityChanged;
use App\EventManagement\Middleware\ClientEventMiddleware;
use App\EventManagement\Middleware\EventHistoryMiddleware;
use App\EventManagement\Middleware\PremisEventMiddleware;
use Tempest\Container\GenericContainer;
use Tempest\EventBus\EventBusConfig;
use Tempest\EventBus\EventBusMiddlewareCallable;
use Tempest\EventBus\GenericEventBus;
use Tests\Support\EventManagement\ClientVisibleEvent;
use Tests\Support\EventManagement\RecordingPremisEventRecorder;

test('metadata generates distinct UUIDv7 identities and normalizes explicit instants to UTC', function (): void {
    $first = new EventMetadata(correlationId: 'run-1', causationId: 'event-0', actorType: 'worker', actorId: 'worker-2');
    $second = new EventMetadata();
    $instant = new DateTimeImmutable('2026-10-29T23:30:00-04:00');
    $restored = new EventMetadata(eventId: 'historic-id', occurredAt: $instant);
    $reconstructed = new EventMetadata(
        eventId: $first->eventId,
        occurredAt: $first->occurredAt,
        correlationId: $first->correlationId,
        causationId: $first->causationId,
        actorType: $first->actorType,
        actorId: $first->actorId,
    );

    expect($first->eventId)
        ->not
        ->toBe($second->eventId)
        ->and($first->eventId)
        ->toMatch('/^[0-9a-f-]{36}$/')
        ->and($first->eventId[14])
        ->toBe('7')
        ->and($first->occurredAt->getTimezone()->getName())
        ->toBe('UTC')
        ->and($restored->eventId)
        ->toBe('historic-id')
        ->and($restored->occurredAt->format('Y-m-d H:i:s'))
        ->toBe('2026-10-30 03:30:00')
        ->and($first->correlationId)
        ->toBe('run-1')
        ->and($first->causationId)
        ->toBe('event-0')
        ->and($first->actorId)
        ->toBe('worker-2')
        ->and($reconstructed->eventId)
        ->toBe($first->eventId)
        ->and($reconstructed->occurredAt)
        ->format('U.u')
        ->toBe($first->occurredAt->format('U.u'));

    expect(fn () => $first->eventId = 'changed')->toThrow(Error::class);
});

test('typed event contracts preserve coverage, partial outcomes, versions and identities', function (): void {
    $metadata = new EventMetadata(eventId: 'stable-event');
    $discovery = new DiscoveryRunCompleted('run-1', DiscoveryCoverage::PARTIAL, $metadata);
    $acquisition = new AcquisitionPartiallyCompleted('acq-1', ['component-2'], $metadata);
    $custody = new AssetCustodyAcknowledged('asset-1', 'component-1', 'receipt-1', $metadata);
    $commit = new AssetVersionCommitted('txn-1', 'asset-1', 'v3', ['evidence-1'], $metadata);
    $publication = new ItemPublicationAcknowledged('pub-1', 'broadcast-1', 'item-1', PublicationOutcome::FAILED, 'fingerprint-1', 'invoke-1', 'destination-rejected', $metadata);
    $checkFailure = new FixityCheckFailed('item-1', 'asset-1', 'v3', 'check-1', 'storage-unavailable', $metadata);
    $pause = new JobPauseAccepted('job-1', 'pause-1', $metadata);
    $acknowledgement = new IncidentAcknowledged('incident-1', $metadata);

    expect($discovery->coverage)
        ->toBe(DiscoveryCoverage::PARTIAL)
        ->and($acquisition->missingComponentIds)
        ->toBe(['component-2'])
        ->and($custody->custodyReceiptId)
        ->not
        ->toBe($commit->transactionId)
        ->and($commit->assetVersion)
        ->toBe('v3')
        ->and($publication->outcome)
        ->toBe(PublicationOutcome::FAILED)
        ->and($publication->reasonCode)
        ->toBe('destination-rejected')
        ->and($checkFailure->reasonCode)
        ->toBe('storage-unavailable')
        ->and($pause->pauseId)
        ->toBe('pause-1')
        ->and($acknowledgement->incidentId)
        ->toBe('incident-1');
});

test('every event namespace exposes constructible event classes with standard metadata', function (): void {
    $events = [
        new StashCreated('stash-1'),
        new InputCreated('input-1'),
        new DiscoveryRunCompleted('run-1', DiscoveryCoverage::EXHAUSTIVE),
        new AcquisitionPartiallyCompleted('acq-1', ['part-2']),
        new AssetCustodyAcknowledged('asset-1', 'component-1', 'receipt-1'),
        new AssetVersionCommitted('txn-1', 'asset-1', 'v1', ['evidence-1']),
        new FixityCheckFailed('item-1', 'asset-1', 'v1', 'check-1', 'unavailable'),
        new ReplicaRegistered('replica-1'),
        new EnrichmentPlanned('enrich-1', 'item-1', 'asset-1', 'v1', 'extract-text', 'plugin-1'),
        new ItemPublicationAcknowledged('pub-1', 'broadcast-1', 'item-1', PublicationOutcome::PUBLISHED, 'fingerprint-1', 'invoke-1', null),
        new JobPauseAccepted('job-1', 'pause-1'),
        new ScheduledWorkDue('schedule-1', 'work-key-1'),
        new IncidentAcknowledged('incident-1'),
        new PluginCapabilityChanged('plugin-1', 'extract-text', CapabilityChange::ADDED),
        new CredentialUpdated('credential-1', 'input:input-1'),
    ];

    expect($events)->toHaveCount(15);
    foreach ($events as $event) {
        expect($event->metadata)->toBeInstanceOf(EventMetadata::class);
    }
});

test('Tempest dispatches the typed event to a real registered handler', function (): void {
    $seen = null;
    $event = new ItemDiscovered('item-1', 'input-1', 'run-1');
    $bus = new GenericEventBus(new GenericContainer(), new EventBusConfig());
    $bus->listen(function (ItemDiscovered $event) use (&$seen): void {
        $seen = $event;
    });

    $bus->dispatch($event);

    expect($seen)
        ->toBe($event)
        ->and($seen->itemId)
        ->toBe('item-1')
        ->and($seen->metadata->eventId)
        ->toBe($event->metadata->eventId);
});

test('history middleware records only classified events and always continues dispatch', function (): void {
    $recorder = new class implements EventHistoryRecorder {
        /** @var list<object> */
        public array $events = [];

        /**
         * Keeps the events forwarded by this focused middleware check.
         *
         * @param object $event
         */
        public function record(object $event): void
        {
            $this->events[] = $event;
        }
    };
    $middleware = new EventHistoryMiddleware($recorder);
    $continued = [];
    $next = new EventBusMiddlewareCallable(function (string|object $event) use (&$continued): void {
        $continued[] = $event;
    });
    $classified = new IncidentAcknowledged('incident-1');
    $unclassified = new ItemDiscovered('item-1', 'input-1', 'run-1');

    $middleware($classified, $next);
    $middleware($unclassified, $next);

    expect($recorder->events)->toBe([$classified])->and($continued)->toBe([$classified, $unclassified]);
});

test('PREMIS middleware selects only marked events while continuing dispatch', function (): void {
    $recorder = new RecordingPremisEventRecorder();
    $middleware = new PremisEventMiddleware($recorder);
    $continued = [];
    $next = new EventBusMiddlewareCallable(function (string|object $event) use (&$continued): void {
        $continued[] = $event;
    });
    $classified = new FixityCheckFailed('item-1', 'asset-1', 'v3', 'check-1', 'unavailable');
    $unclassified = new ItemDiscovered('item-1', 'input-1', 'run-1');

    $middleware($classified, $next);
    $middleware($unclassified, $next);

    expect($recorder->events)
        ->toBe([$classified])
        ->and($continued)
        ->toBe([$classified, $unclassified]);
});

test('client middleware publishes only allowlisted scalar properties', function (): void {
    $publisher = new class implements ClientEventPublisher {
        public ?ClientEvent $event = null;

        /**
         * Retains one client projection for a focused allowlist assertion.
         */
        public function publish(ClientEvent $event): void
        {
            $this->event = $event;
        }
    };
    $middleware = new ClientEventMiddleware($publisher);
    $fixture = new ClientVisibleEvent('item-1', 'must-not-leak');
    $next = new EventBusMiddlewareCallable(static function (string|object $event): void {});

    $middleware($fixture, $next);

    expect($publisher->event)
        ->toBeInstanceOf(ClientEvent::class)
        ->and($publisher->event->topic)
        ->toBe('test.item')
        ->and($publisher->event->fields)
        ->toBe(['itemId' => 'item-1'])
        ->and($publisher->event->eventId)
        ->toBe($fixture->metadata->eventId);
});

test('client transport failure does not escape middleware after normal dispatch', function (): void {
    $publisher = new class implements ClientEventPublisher {
        /**
         * Simulates an unavailable notification transport.
         *
         * @throws RuntimeException The fake transport is intentionally unavailable.
         */
        public function publish(ClientEvent $event): void
        {
            throw new RuntimeException('transport unavailable');
        }
    };
    $continued = false;
    $middleware = new ClientEventMiddleware($publisher);
    $next = new EventBusMiddlewareCallable(static function (string|object $event) use (&$continued): void {
        $continued = true;
    });

    $middleware(new ClientVisibleEvent('item-1', 'secret'), $next);

    expect($continued)->toBeTrue();
});

test('future event middleware explicitly opts out of Tempest discovery', function (): void {
    foreach ([
        \App\EventManagement\Middleware\EventHistoryMiddleware::class,
        \App\EventManagement\Middleware\PremisEventMiddleware::class,
        \App\EventManagement\Middleware\ClientEventMiddleware::class,
    ] as $middleware) {
        expect(new ReflectionClass($middleware)->getAttributes(\Tempest\Discovery\SkipDiscovery::class))->not->toBeEmpty();
    }
});

test('catalogue audit reports all definitions without claiming unimplemented coverage', function (): void {
    $report = new EventCatalogueAudit()->audit(dirname(__DIR__, 2));

    expect($report->isComplete())
        ->toBeTrue()
        ->and(count($report->coverage))
        ->toBe(181)
        ->and($report->missingClasses)
        ->toBe([])
        ->and($report->unexpectedClasses)
        ->toBe([])
        ->and(array_filter($report->coverage, static fn (array $row): bool => $row['emitted'] || $row['verified'] || $row['consumed']))
        ->toBe([]);
});

test('catalogue audit identifies missing and unexpected classes', function (): void {
    $base = sys_get_temp_dir() . '/stashd-event-audit-' . bin2hex(random_bytes(5));
    $audit = new EventCatalogueAudit();
    $missingRoot = $base . '/missing';
    mkdir($missingRoot . '/docs', 0o777, true);
    mkdir($missingRoot . '/app/EventManagement/Events', 0o777, true);
    file_put_contents(
        $missingRoot . '/docs/event-management-catalogue.md',
        '| `App\\EventManagement\\Events\\Catalogue\\ItemDiscovered` | expected |',
    );
    $missing = $audit->audit($missingRoot);

    $unexpectedRoot = $base . '/unexpected';
    $events = $unexpectedRoot . '/app/EventManagement/Events/Catalogue';
    mkdir($unexpectedRoot . '/docs', 0o777, true);
    mkdir($events, 0o777, true);
    file_put_contents($unexpectedRoot . '/docs/event-management-catalogue.md', '# Empty fixture catalogue');
    $fixture = dirname(__DIR__, 2) . '/tests/Support/EventManagement/UnexpectedEvent.php';
    copy($fixture, $events . '/UnexpectedEvent.php');
    require_once $fixture;
    $unexpected = $audit->audit($unexpectedRoot);

    unlink($events . '/UnexpectedEvent.php');
    rmdir($events);
    rmdir($unexpectedRoot . '/app/EventManagement/Events');
    rmdir($unexpectedRoot . '/app/EventManagement');
    rmdir($unexpectedRoot . '/app');
    unlink($unexpectedRoot . '/docs/event-management-catalogue.md');
    rmdir($unexpectedRoot . '/docs');
    rmdir($unexpectedRoot);
    unlink($missingRoot . '/docs/event-management-catalogue.md');
    rmdir($missingRoot . '/docs');
    rmdir($missingRoot . '/app/EventManagement/Events');
    rmdir($missingRoot . '/app/EventManagement');
    rmdir($missingRoot . '/app');
    rmdir($missingRoot);
    rmdir($base);

    expect($missing->missingClasses)
        ->toBe(['App\\EventManagement\\Events\\Catalogue\\ItemDiscovered'])
        ->and($unexpected->unexpectedClasses)
        ->toBe(['App\\EventManagement\\Events\\Catalogue\\UnexpectedEvent']);
});

test('reviewed boundaries need no assigned work or future committed version', function (): void {
    $worker = new \App\EventManagement\Events\Execution\WorkerStarted('idle-worker');
    $resume = new \App\EventManagement\Events\Acquisition\AcquisitionResumed('acquisition-1', 'checkpoint-4');
    $custody = new AssetCustodyAcknowledged('asset-1', 'component-1', 'receipt-1');
    $pin = new \App\EventManagement\DTO\AssetVersionReference('asset-1', 'v12');
    $commit = new \App\EventManagement\Events\Preservation\ItemVersionCommitted('txn-1', 'item-1', 'v3', [$pin]);

    expect($worker->workerId)
        ->toBe('idle-worker')
        ->and($resume->acquisitionId)
        ->toBe('acquisition-1')
        ->and($resume->checkpointId)
        ->toBe('checkpoint-4')
        ->and($custody->componentId)
        ->toBe('component-1')
        ->and($commit->assetVersionReferences[0]->assetId)
        ->toBe('asset-1')
        ->and($commit->assetVersionReferences[0]->version)
        ->toBe('v12');
    expect(fn () => new \App\EventManagement\DTO\AssetVersionReference('asset-1', 'latest'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new \App\EventManagement\Events\Preservation\ItemVersionCommitted('txn-1', 'item-1', 'v3', ['asset-1:v12']))->toThrow(InvalidArgumentException::class);
});

test('assertions about one event never certify coverage for another event', function (): void {
    $root = sys_get_temp_dir() . '/stashd-coverage-isolation-' . bin2hex(random_bytes(5));
    mkdir($root . '/docs', 0o777, true);
    mkdir($root . '/tests', 0o777, true);
    mkdir($root . '/app/EventManagement/Events/Catalogue', 0o777, true);
    $catalogue = '';
    foreach (['ItemDiscovered', 'StashCreated'] as $name) {
        copy(dirname(__DIR__, 2) . '/app/EventManagement/Events/Catalogue/' . $name . '.php', $root . '/app/EventManagement/Events/Catalogue/' . $name . '.php');
        $catalogue .= '`App\\EventManagement\\Events\\Catalogue\\' . $name . "`\n";
    }

    file_put_contents($root . '/docs/event-management-catalogue.md', $catalogue);
    file_put_contents($root . '/tests/Example.php', '<?php $bus->assertDispatched(ItemDiscovered::class); $bus->assertNotDispatched(ItemDiscovered::class);');
    $report = new EventCatalogueAudit()->audit($root);
    foreach ($report->coverage as $stages) {
        expect($stages['verified'])
            ->toBeFalse()
            ->and($stages['emitted'])
            ->toBeFalse()
            ->and($stages['consumed'])
            ->toBeFalse();
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $path => $_) {
        is_dir($path) ? rmdir($path) : unlink($path);
    }

    rmdir($root);
});

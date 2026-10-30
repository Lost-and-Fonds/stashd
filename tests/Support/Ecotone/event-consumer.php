<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\DbalConnection;
use Tests\Support\Ecotone\CatalogueSubscribers;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// The parent provides restricted credentials for one disposable fixture only.
$request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$db = DriverManager::getConnection($request['connection']);
$db->executeStatement('SET search_path TO "' . $request['schema'] . '"');
$system = EcotoneLite::bootstrap(
    classesToResolve: [CatalogueSubscribers::class],
    containerOrAvailableServices: [CatalogueSubscribers::class => new CatalogueSubscribers($db, $request['fail']), DbalConnectionReference::DEFAULT => DbalConnection::create($db)],
    extensionObjects: [
        DbalBackedMessageChannelBuilder::create('history')->withReceiveTimeout(1),
        DbalBackedMessageChannelBuilder::create('premis')->withReceiveTimeout(1),
        DbalBackedMessageChannelBuilder::create('client')->withReceiveTimeout(1),
        DbalConfiguration::createWithDefaults()->withDeduplication(false)->withDeadLetter(false),
    ],
);
try {
    $system->run($request['channel'], ExecutionPollingMetadata::createWithFinishWhenNoMessages());
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}

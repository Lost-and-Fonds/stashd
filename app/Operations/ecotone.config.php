<?php

use Ecotone\Tempest\EcotoneConfig;

use function Tempest\env;

$namespaces = ['App\\Operations'];
if (env('ENVIRONMENT') === 'testing' && env('STASHD_CATALOGUE_PROOF', default: '0') === '1') {
    $namespaces[] = 'Tests\\Support\\Ecotone\\Catalogue';
}

return new EcotoneConfig(namespaces: $namespaces, loadAppNamespaces: false, consoleCommandPrefixes: ['ecotone:']);

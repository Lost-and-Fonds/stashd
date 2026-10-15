<?php

namespace App\RecordsAndArchives;

use Tempest\Storage\Storage;

interface StashdStorage extends Storage
{
    public function supports(StorageCapability $capability): bool;
}
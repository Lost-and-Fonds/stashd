<?php

namespace App\RecordsAndArchives\Storage;

use Tempest\Storage\Storage;

interface StashdStorage extends Storage
{
    public function supports(StorageCapability $capability): bool;
}
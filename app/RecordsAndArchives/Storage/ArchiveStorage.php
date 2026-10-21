<?php

namespace App\RecordsAndArchives\Storage;

use Tempest\Storage\Storage;

interface ArchiveStorage extends Storage
{
    public function supports(StorageCapability $capability): bool;
}

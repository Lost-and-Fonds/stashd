<?php

namespace App\RecordsAndArchives\Storage;

use Tempest\Storage\Storage;

/**
 * Extend Tempest storage with publication capabilities required by archival adapters.
 *
 * Implementations report supported primitives explicitly; callers must not infer
 * atomic publication from the generic Storage interface alone.
 */
interface ArchiveStorage extends Storage
{
    /**
     * Report whether this provider offers the requested publication primitive.
     */
    public function supports(StorageCapability $capability): bool;
}

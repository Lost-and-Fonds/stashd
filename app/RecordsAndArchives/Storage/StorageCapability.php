<?php

namespace App\RecordsAndArchives\Storage;

/**
 * Name storage primitives relevant to safe archival publication.
 *
 * Capability declarations describe provider operations, not multi-file transactions
 * or guarantees that every generic storage method is atomic.
 */
enum StorageCapability
{
    case AtomicDirectoryRename;
    case AtomicFileReplace;
    case ConditionalWrite;
}

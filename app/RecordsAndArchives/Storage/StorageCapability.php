<?php

namespace App\RecordsAndArchives\Storage;

enum StorageCapability
{
    case AtomicDirectoryRename;
    case AtomicFileReplace;
    case ConditionalWrite;
}
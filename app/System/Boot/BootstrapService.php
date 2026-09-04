<?php

declare(strict_types=1);

namespace App\System\Boot;

use App\System\Storage\StorageCapabilityChecker;
use App\System\Storage\StorageRootService;

final readonly class BootstrapService
{
    public function __construct(
        private StorageRootService $storageRoots,
        private StorageCapabilityChecker $storageChecks,
        private MigrationRunner $migrations,
    ) {}

    /** @return array{directories_created: list<string>} */
    public function boot(): array
    {
        $created = $this->storageRoots->ensureDirectories();
        $this->migrations->run();
        $this->storageChecks->checkAll();

        return ['directories_created' => $created];
    }
}

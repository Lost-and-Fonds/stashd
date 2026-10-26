<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\AcquiredFile;
use App\Integration\DTO\DiscoveredItem;

interface InputAcquisition
{
    public function acquire(string $inputId, array $options, DiscoveredItem $item, AcquisitionStaging $staging): AcquiredFile;
}

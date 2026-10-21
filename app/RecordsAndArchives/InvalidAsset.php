<?php

namespace App\RecordsAndArchives;

use InvalidArgumentException;

/**
 * Signal invalid Asset paths, acquisition input or requested logical content to callers.
 */
final class InvalidAsset extends InvalidArgumentException {}

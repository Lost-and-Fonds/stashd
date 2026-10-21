<?php

namespace App\RecordsAndArchives;

use InvalidArgumentException;

/**
 * Signal invalid canonical Item identity, metadata or Asset pins before publication.
 */
final class InvalidItemRevision extends InvalidArgumentException {}

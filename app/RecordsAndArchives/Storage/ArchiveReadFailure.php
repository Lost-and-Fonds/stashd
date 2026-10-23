<?php

namespace App\RecordsAndArchives\Storage;

use RuntimeException;

/**
 * Signal unavailable storage observations that cannot establish archival corruption.
 *
 * Recovery must abort rather than replace verified projections with degradation
 * incidents when a provider cannot return the requested evidence.
 */
final class ArchiveReadFailure extends RuntimeException {}

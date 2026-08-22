<?php

declare(strict_types=1);

/**
 * Resolve Tempest internal storage (cache, logs, sessions).
 *
 * In Docker, STASHD_DATA_PATH is an absolute path on the data volume (/data).
 * Writable runtime state belongs there — not under the application root.
 */
function tempest_internal_storage(): ?string
{
    $explicit = getenv('TEMPEST_INTERNAL_STORAGE');
    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }

    $dataPath = getenv('STASHD_DATA_PATH') ?: getenv('DATA_PATH');
    if (is_string($dataPath) && str_starts_with($dataPath, '/')) {
        return rtrim($dataPath, '/').'/.tempest';
    }

    return null;
}

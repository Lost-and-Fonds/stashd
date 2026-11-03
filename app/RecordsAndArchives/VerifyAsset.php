<?php

declare(strict_types=1);

namespace App\RecordsAndArchives;

use App\Operations\Job;
use InvalidArgumentException;
use League\Uri\Urn;
use Tempest\Validation\Rules\MatchesRegEx;

/**
 * Request a read-only check of one exact Asset Object version against its stored evidence.
 *
 * Equal inputs share active work. A later request after completion checks again;
 * neither identity represents the current health of other versions or future bytes.
 */
final readonly class VerifyAsset implements Job
{
    /**
     * Retain public scalar input for Operations serialization and reject non-exact versions.
     */
    public function __construct(
        public string $objectId,
        public string $version,
    ) {
        Urn::new($objectId);
        if (! new MatchesRegEx('/^v0*[1-9][0-9]*$/D')->isValid($version)) {
            throw new InvalidArgumentException('Verification requires an exact OCFL version.');
        }
    }

    /**
     * Coalesce only the same Object and exact version, independently of the current head.
     */
    public function key(): string
    {
        return hash('sha256', json_encode([$this->objectId, $this->version], JSON_THROW_ON_ERROR));
    }

    /**
     * Identify the same immutable version input across retries and equivalent admissions.
     */
    public function observation(): string
    {
        return $this->key();
    }
}

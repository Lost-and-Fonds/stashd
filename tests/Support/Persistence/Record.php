<?php

declare(strict_types=1);

namespace Tests\Support\Persistence;

use App\Persistence\Model;
use DateTimeImmutable;
use Tempest\Database\HasMany;
use Tempest\Validation\Rules\IsNotEmptyString;

/**
 * Supply domain fields for isolated models sharing the same disposable table.
 */
abstract class Record extends Model
{
    /** @var list<AllowedRecord> */
    #[HasMany]
    public array $related;

    /**
     * Keep a domain timestamp independent of the shared persistence timestamps.
     */
    public function __construct(
        #[IsNotEmptyString]
        public string $value,
        public ?DateTimeImmutable $started_at = null,
    ) {}
}
